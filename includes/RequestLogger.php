<?php

namespace CirrusSearch;

use DeferredUpdates;
use Elastica\Client;
use MediaWiki\Logger\LoggerFactory;
use RequestContext;
use SearchResultSet;
use Title;
use User;

/**
 * Handles logging information about requests made to various destinations,
 * such as monolog and statsd.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class RequestLogger {
	/**
	 * @const int max number of results to store in CirrusSearchRequestSet logs (per request)
	 */
	const LOG_MAX_RESULTS = 50;

	/**
	 * @var array[] Result of self::getLogContext for each request in this process
	 */
	private $logContexts = [];

	/**
	 * @var array[string] Result page ids that were returned to user
	 */
	private $resultTitleStrings = [];

	/**
	 * Summarizes all the requests made in this process and reports
	 * them along with the test they belong to.
	 */
	private function reportLogContexts() {
		if ( $this->logContexts ) {
			$this->buildRequestSetLog();
			$this->logContexts = [];
		}
	}

	public function addRequest( $description, array $context, $tookMs, Client $client = null, $slowMillis = null, User $user = null ) {
		global $wgCirrusSearchLogElasticRequests;

		// Note that this had side-effects, so even if the result is unused
		// it's still doing "something"
		$finalContext = $this->buildLogContext( $context, $tookMs, $client );
		if ( $wgCirrusSearchLogElasticRequests ) {
			if ( count( $this->logContexts ) === 0 ) {
				DeferredUpdates::addCallableUpdate( function () {
					$this->reportLogContexts();
				} );
			}
			$this->logContexts[] = $finalContext;

			$logMessage = $this->buildLogMessage( $description, $finalContext );
			LoggerFactory::getInstance( 'CirrusSearchRequests' )->debug( $logMessage, $finalContext );
			if ( $slowMillis && $tookMs >= $slowMillis ) {
				if ( $user ) {
					$finalContext['user'] = $user->getName();
					$logMessage .= ' for {user}';
				}
				LoggerFactory::getInstance( 'CirrusSearchSlowRequests' )->info( $logMessage, $finalContext );
			}
		}

		return $finalContext;
	}


	/**
	 * @param array $values
	 */
	public function appendLastLogContext( array $values ) {
		$idx = count( $this->logContexts ) - 2;
		if ( $idx >= 0 ) {
			$this->logContexts[$idx] += $values;
		}
	}

	/**
	 * Report the types of queries that were issued
	 * within the current request.
	 *
	 * @return string[]
	 */
	public function getQueryTypesUsed() {
		$types = [];
		foreach ( $this->logContexts as $context ) {
			if ( isset( $context['queryType'] ) ) {
				$types[] = $context['queryType'];
			}
		}
		return array_unique( $types );
	}

	/**
	 * This is set externally because we don't have complete control, from the
	 * SearchEngine interface, of what is actually sent to the user. Instead hooks
	 * receive the final results that will be sent to the user and set them here.
	 *
	 * Accepts two result sets because some places (Special:Search) perform multiple
	 * searches. This can be called multiple times, but only that last call wins. For
	 * API's that is correct, for Special:Search a hook catches the final results and
	 * sets them here.
	 *
	 * @param array[Search\ResultSet|null] $matches
	 */
	public function setResultPages( array $matches ) {
		$titleStrings = [];
		foreach ( $matches as $resultSet ) {
			if ( $resultSet !== null ) {
				$titleStrings = array_merge( $titleStrings, $this->extractTitleStrings( $resultSet ) );
			}
		}
		$this->resultTitleStrings = $titleStrings;
	}

	/**
	 * Builds and ships a log context that is serialized to an avro
	 * schema. Avro is very specific that all fields must be defined,
	 * even if they have a default, and that types must match exactly.
	 * "5" is not an int as much as php would like it to be.
	 *
	 * Avro will happily ignore fields that are present but not used. To
	 * add new fields to the schema they must first be added here and
	 * deployed. Then the schema can be updated. Removing goes in reverse,
	 * adjust the schema to ignore the column, then deploy code no longer
	 * providing it.
	 */
	private function buildRequestSetLog() {
		global $wgRequest;

		// for the moment these are still created in the old format to serve
		// the old log formats, so here we transform the context into the new
		// request format. At some point the context should just be created in
		// the correct format.
		$requests = [];
		$allCached = true;
		$allHits = [];
		foreach ( $this->logContexts as $context ) {
			$request = [
				'query' => isset( $context['query'] ) ? (string) $context['query'] : '',
				'queryType' => isset( $context['queryType'] ) ? (string) $context['queryType'] : '',
				// populated below
				'indices' => [],
				'tookMs' => isset( $context['tookMs'] ) ? (int) $context['tookMs'] : -1,
				'elasticTookMs' => isset( $context['elasticTookMs'] ) ? (int) $context['elasticTookMs'] : -1,
				'limit' => isset( $context['limit'] ) ? (int) $context['limit'] : -1,
				'hitsTotal' => isset( $context['hitsTotal'] ) ? (int) $context['hitsTotal'] : -1,
				'hitsReturned' => isset( $context['hitsReturned'] ) ? (int) $context['hitsReturned'] : -1,
				'hitsOffset' => isset( $context['hitsOffset'] ) ? (int) $context['hitsOffset'] : -1,
				// populated below
				'namespaces' => [],
				'suggestion' => isset( $context['suggestion'] ) ? (string) $context['suggestion'] : '',
				'suggestionRequested' => isset( $context['suggestion'] ),
				'maxScore' => isset( $context['maxScore'] ) ? $context['maxScore'] : -1,
				'payload' => [],
				'hits' => isset( $context['hits'] ) ? array_slice( $context['hits'], 0, self::LOG_MAX_RESULTS ) : [],
			];
			if ( isset( $context['hits'] ) ) {
				$allHits = array_merge( $allHits, $context['hits'] );
			}
			if ( isset( $context['index'] ) ) {
				$request['indices'][] = $context['index'];
			}
			if ( isset( $context['namespaces'] ) ) {
				foreach ( $context['namespaces'] as $nsId ) {
					$request['namespaces'][] = (int) $nsId;
				}
			}
			if ( !empty( $context['langdetect' ] ) ) {
				$request['payload']['langdetect'] = (string) $context['langdetect'];
			}
			if ( isset( $context['cached'] ) && $context['cached'] ) {
				$request['payload']['cached'] = 'true';
			} else {
				$allCached = false;
			}

			if ( isset( $context['timing'] ) ) {
				$start = 0;
				if ( isset( $context['timing']['start'] ) ) {
					$start = $context['timing']['start'];
					unset( $context['timing']['start'] );
				}
				foreach ( $context['timing'] as $name => $time ) {
					$request['payload']["timing-$name"] = (string) intval(( $time - $start ) * 1000);
				}
			}

			$requests[] = $request;
		}

		// Note that this is only accurate for hhvm and php-fpm
		// since they close the request to the user before running
		// deferred updates.
		$timing = \RequestContext::getMain()->getTiming();
		$startMark = $timing->getEntryByName( 'requestStart' );
		$endMark  = $timing->getEntryByName( 'requestShutdown' );
		if ( $startMark && $endMark ) {
			// should always work, but Timing can return null so
			// fallbacks are provided.
			$tookS = $endMark['startTime'] - $startMark['startTime'];
		} elseif( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			// php >= 5.4
			$tookS = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
		} else {
			// php 5.3
			$tookS = microtime( true ) - $_SERVER['REQUEST_TIME'];
		}

		// Reindex allHits by page title's. It's maybe not perfect, but it's
		// hopefully a "close enough" representation of where our final result
		// set came from. maybe :(
		$allHitsByTitle = [];
		foreach ( $allHits as $hit ) {
			$allHitsByTitle[$hit['title']] = $hit;
		}
		$resultHits = [];
		// FIXME: temporary hack to investigate why SpecialSearch can display results
		// that do not come from cirrus.
		$bogusResult = null;
		foreach ( $this->resultTitleStrings as $titleString ) {
			// Track only the first missing title.
			if ( $bogusResult === null && !isset( $allHitsByTitle[$titleString] ) ) {
				$bogusResult = $titleString;
			}

			$hit = isset( $allHitsByTitle[$titleString] ) ? $allHitsByTitle[$titleString] : [];
			// Apply defaults to ensure all properties are accounted for.
			$resultHits[] = $hit + [
				'title' => $titleString,
				'index' => "",
				'pageId' => -1,
				'score' => -1,
				'profileName' => ""
			];
		}

		$requestSet = [
			'id' => Util::getRequestSetToken(),
			'ts' => time(),
			'wikiId' => wfWikiID(),
			'source' => Util::getExecutionContext(),
			'identity' => Util::generateIdentToken(),
			'ip' => $wgRequest->getIP() ?: '',
			'userAgent' => $wgRequest->getHeader( 'User-Agent') ?: '',
			'backendUserTests' => UserTesting::getInstance()->getActiveTestNamesWithBucket(),
			'tookMs' => 1000 * $tookS,
			'hits' => array_slice( $resultHits, 0, self::LOG_MAX_RESULTS ),
			'payload' => [
				// useful while we are testing accept-lang based interwiki
				'acceptLang' => (string) ($wgRequest->getHeader( 'Accept-Language' ) ?: ''),
				// Helps to track down what actually caused the request. Will promote to full
				// param if it proves useful
				'queryString' => http_build_query( $_GET ),
				// When tracking down performance issues it is useful to know if they are localized
				// to a particular set of instances
				'host' => gethostname(),
			],
			'requests' => $requests,
		];

		if ( $bogusResult !== null ) {
			if ( is_string( $bogusResult ) ) {
				$requestSet['payload']['bogusResult'] = $bogusResult;
			} else {
				$requestSet['payload']['bogusResult'] = 'NOT_A_STRING?: ' . gettype( $bogusResult );
			}
		}

		if ( $allCached ) {
			$requestSet['payload']['cached'] = 'true';
		}

		LoggerFactory::getInstance( 'CirrusSearchRequestSet' )->debug( '', $requestSet );
	}

	private function extractTitleStrings( SearchResultSet $matches ) {
		$strings = [];
		$result = $matches->next();
		while ( $result ) {
			$strings[] = (string) $result->getTitle();
			$result = $matches->next();
		}
		$matches->rewind();
		return $strings;
	}

	/**
	 * @param string $description psr-3 compatible logging string, will be combined with $context
	 * @param array $context Request specific log variables from self::buildLogContext()
	 * @return string a PSR-3 compliant message describing $context
	 */
	private function buildLogMessage( $description, array $context ) {
		// No need to check description because it must be set by $this->start.
		$message = $description;
		$message .= " against {index} took {tookMs} millis";
		if ( isset( $context['elasticTookMs'] ) ) {
			$message .= " and {elasticTookMs} Elasticsearch millis";
			if ( isset( $context['elasticTook2PassMs'] ) ) {
				$message .= " (with 2nd pass: {elasticTook2PassMs} ms)";
			}
		}
		if ( isset( $context['hitsTotal'] ) ){
			$message .= ". Found {hitsTotal} total results";
			$message .= " and returned {hitsReturned} of them starting at {hitsOffset}";
		}
		if ( isset( $context['namespaces'] ) ) {
			$namespaces = implode( ', ', $context['namespaces'] );
			$message .= " within these namespaces: $namespaces";
		}
		if ( isset( $context['suggestion'] ) && strlen( $context['suggestion'] ) > 0 ) {
			$message .= " and suggested '{suggestion}'";
		}
		$message .= ". Requested via {source} for {identity} by executor {executor}";

		return $message;
	}

	/**
	 * These values end up serialized into Avro which has strict typing
	 * requirements. float !== int !== string.
	 *
	 * Note that this really only handles the "standard" search response
	 * format from elasticsearch. The completion suggester is a bit of a
	 * special snowflake in that it has a completely different response
	 * format than other searches. The CirrusSearch\CompletionSuggester
	 * class is responsible for providing any useful logging data by adding
	 * directly to $this->logContext.
	 *
	 * @param array $params
	 * @param float $took Number of milliseconds the request took
	 * @param Client|null $client
	 * @return array
	 */
	private function buildLogContext( array $params, $took, Client $client = null ) {
		if ( $client ) {
			$query = $client->getLastRequest();
			$result = $client->getLastResponse();
		} else {
			$query = null;
			$result = null;
		}

		$params += [
			'tookMs' => intval( $took ),
			'source' => Util::getExecutionContext(),
			'executor' => Util::getExecutionId(),
			'identity' => Util::generateIdentToken(),
		];

		if ( $result ) {
			$queryData = $query->getData();
			$resultData = $result->getData();

			$index = explode( '/', $query->getPath() );
			$params['index'] = $index[0];
			if ( isset( $resultData[ 'took' ] ) ) {
				$elasticTook = $resultData[ 'took' ];
				$params['elasticTookMs'] = intval( $elasticTook );
			}
			if ( isset( $resultData['hits']['total'] ) ) {
				$params['hitsTotal'] = intval( $resultData['hits']['total'] );
			}
			if ( isset( $resultData['hits']['max_score'] ) ) {
				$params['maxScore'] = $resultData['hits']['max_score'];
			}
			if ( isset( $resultData['hits']['hits'] ) ) {
				$num = count( $resultData['hits']['hits'] );
				$offset = isset( $queryData['from'] ) ? $queryData['from'] : 0;
				$params['hitsReturned'] = $num;
				$params['hitsOffset'] = intval( $offset );
				$params['hits'] = [];
				foreach ( $resultData['hits']['hits'] as $hit ) {
					if ( !isset( $hit['_source']['namespace'] )
						|| !isset( $hit['_source']['title'] )
					) {
						// This is probably a query that does not return pages
						// like geo or namespace queries
						continue;
					}
					// duplication of work ... this happens in the transformation
					// stage but we can't see that here...Perhaps we instead attach
					// this data at a later stage like CompletionSuggester?
					$title = Title::makeTitle( $hit['_source']['namespace'], $hit['_source']['title'] );
					$params['hits'][] = [
						// This *must* match the names and types of the CirrusSearchHit
						// record in the CirrusSearchRequestSet logging channel avro schema.
						'title' => (string) $title,
						'index' => isset( $hit['_index'] ) ? $hit['_index'] : "",
						'pageId' => isset( $hit['_id'] ) ? (int) $hit['_id'] : -1,
						'score' => isset( $hit['_score'] ) ? (float) $hit['_score'] : -1,
						// only comp_suggest has profileName, and that is handled
						// elsewhere
						'profileName' => "",
					];
				}
			}
			if ( isset( $queryData['query']['filtered']['filter']['terms']['namespace'] ) ) {
				$namespaces = $queryData['query']['filtered']['filter']['terms']['namespace'];
				$params['namespaces'] = array_map( 'intval', $namespaces );
			}
			if ( isset( $resultData['suggest']['suggest'][0]['options'][0]['text'] ) ) {
				$params['suggestion'] = $resultData['suggest']['suggest'][0]['options'][0]['text'];
			}
		}

		return $params;
	}
}
