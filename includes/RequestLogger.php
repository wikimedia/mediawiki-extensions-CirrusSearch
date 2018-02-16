<?php

namespace CirrusSearch;

use DeferredUpdates;
use MediaWiki\Logger\LoggerFactory;
use SearchResultSet;
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
	 * @var RequestLog[] Set of requests made
	 */
	private $logs = [];

	/**
	 * @var array[string] Result page ids that were returned to user
	 */
	private $resultTitleStrings = [];

	/**
	 * @var string[][] Extra payload for the logs, indexed first by the log index
	 *  in self::$logs, and second by the payload item name.
	 */
	private $extraPayload = [];

	/**
	 * Summarizes all the requests made in this process and reports
	 * them along with the test they belong to.
	 */
	private function reportLogs() {
		if ( $this->logs ) {
			$this->buildRequestSetLog();
			$this->logs = [];
		}
	}

	/**
	 * @param RequestLog $log The log about a network request to be added
	 * @param User|null $user The user performing the request, or null
	 *  for actions that don't have a user (such as index updates).
	 * @param int|null $slowMillis The threshold in ms after which the request
	 *  will be considered slow.
	 * @return array A map of information about the performed request, suitible
	 *  for use as a psr-3 log context.
	 */
	public function addRequest( RequestLog $log, User $user = null, $slowMillis = null ) {
		global $wgCirrusSearchLogElasticRequests;

		// @todo Is this necessary here? Check on what uses the response value
		$finalContext = $log->getLogVariables() + [
			'source' => Util::getExecutionContext(),
			'executor' => Util::getExecutionId(),
			'identity' => Util::generateIdentToken(),
		];
		if ( $wgCirrusSearchLogElasticRequests ) {
			$this->logs[] = $log;
			if ( count( $this->logs ) === 1 ) {
				DeferredUpdates::addCallableUpdate( function () {
					$this->reportLogs();
				} );
			}

			$logMessage = $this->buildLogMessage( $log, $finalContext );
			LoggerFactory::getInstance( 'CirrusSearchRequests' )->debug( $logMessage, $finalContext );
			if ( $slowMillis && $log->getTookMs() >= $slowMillis ) {
				if ( $user !== null ) {
					$finalContext['user'] = $user->getName();
					$logMessage .= ' for {user}';
				}
				LoggerFactory::getInstance( 'CirrusSearchSlowRequests' )->info( $logMessage, $finalContext );
			}
		}

		return $finalContext;
	}

	/**
	 * @param string $key
	 * @param string $value
	 */
	public function appendLastLogPayload( $key, $value ) {
		$idx = count( $this->logs ) - 1;
		if ( isset( $this->logs[$idx] ) ) {
			$this->extraPayload[$idx][$key] = $value;
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
		foreach ( $this->logs as $log ) {
			$types[] = $log->getQueryType();
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
	 * To ensure no problems serializing all properties must be explicitly
	 * cast to the correct type.
	 *
	 * Avro will happily ignore fields that are present but not used. To
	 * add new fields to the schema they must first be added here and
	 * deployed. Then the schema can be updated. Removing goes in reverse,
	 * adjust the schema to ignore the column, then deploy code no longer
	 * providing it.
	 *
	 * All default values should match those use in the
	 * CirrusSearchRequestSet.idl (mediawiki-event-schemas repository)
	 */
	private function buildRequestSetLog() {
		global $wgRequest;

		// for the moment RequestLog::getRequests() is still created in the
		// old format to serve the old log formats, so here we transform the
		// context into the new avro defined format. At some point the context
		// should just be created in the correct format.
		$requests = [];
		$allCached = true;
		$allHits = [];
		foreach ( $this->logs as $idx => $log ) {
			foreach ( $log->getRequests() as $context ) {
				$request = [
					'query' => isset( $context['query'] ) ? (string)$context['query'] : '',
					'queryType' => isset( $context['queryType'] ) ? (string)$context['queryType'] : '',
					// populated below
					'indices' => isset( $context['index'] ) ? explode( ',', $context['index'] ) : [],
					'tookMs' => isset( $context['tookMs'] ) ? (int)$context['tookMs'] : -1,
					'elasticTookMs' => isset( $context['elasticTookMs'] ) ? (int)$context['elasticTookMs'] : -1,
					'limit' => isset( $context['limit'] ) ? (int)$context['limit'] : -1,
					'hitsTotal' => isset( $context['hitsTotal'] ) ? (int)$context['hitsTotal'] : -1,
					'hitsReturned' => isset( $context['hitsReturned'] ) ? (int)$context['hitsReturned'] : -1,
					'hitsOffset' => isset( $context['hitsOffset'] ) ? (int)$context['hitsOffset'] : -1,
					// populated below
					'namespaces' => isset( $context['namespaces'] ) ? array_map( 'intval', $context['namespaces'] ) : [],
					'suggestion' => isset( $context['suggestion'] ) ? (string)$context['suggestion'] : '',
					'suggestionRequested' => isset( $context['suggestion'] ),
					'maxScore' => isset( $context['maxScore'] ) ? (float)$context['maxScore'] : -1.0,
					'payload' => isset( $context['payload'] ) ? array_map( 'strval', $context['payload'] ) : [],
					'hits' => isset( $context['hits'] ) ? $this->encodeHits( $context['hits'] ) : [],
				];
				if ( !empty( $context['syntax'] ) ) {
					$request['payload']['syntax'] = implode( ',', $context['syntax'] );
				}
				$allHits = array_merge( $allHits, $request['hits'] );
				if ( $log->isCachedResponse() ) {
					$request['payload']['cached'] = 'true';
				} else {
					$allCached = false;
				}
				if ( isset( $this->extraPayload[$idx] ) ) {
					foreach ( $this->extraPayload[$idx] as $key => $value ) {
						$request['payload'][$key] = (string)$value;
					}
				}

				$requests[] = $request;
			}
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
		$resultTitleStrings = array_slice( $this->resultTitleStrings, 0, self::LOG_MAX_RESULTS );
		foreach ( $resultTitleStrings as $titleString ) {
			// Track only the first missing title.
			if ( $bogusResult === null && !isset( $allHitsByTitle[$titleString] ) ) {
				$bogusResult = $titleString;
			}

			$resultHits[] = isset( $allHitsByTitle[$titleString] ) ? $allHitsByTitle[$titleString] : [
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
			'userAgent' => $wgRequest->getHeader( 'User-Agent' ) ?: '',
			'backendUserTests' => UserTesting::getInstance()->getActiveTestNamesWithBucket(),
			'tookMs' => $this->getPhpRequestTookMs(),
			'hits' => $resultHits,
			'payload' => [
				// useful while we are testing accept-lang based interwiki
				'acceptLang' => (string)( $wgRequest->getHeader( 'Accept-Language' ) ?: '' ),
				// Helps to track down what actually caused the request. Will promote to full
				// param if it proves useful
				'queryString' => http_build_query( $_GET ),
				// When tracking down performance issues it is useful to know if they are localized
				// to a particular set of instances
				'host' => gethostname(),
				// Referer can be helpful when trying to figure out what requests were made by bots.
				'referer' => (string)( $wgRequest->getHeader( 'Referer' ) ?: '' ),
				// Reasonable indication it's not a "normal" web request, as a standard web request
				// would have first had a WMF-Last-Access cookie set, and then submitting an
				// autocomplete/fulltext search would have sent that as well.
				'hascookies' => $wgRequest->getHeader( 'Cookie' ) ? "1" : "0",
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

	/**
	 * @param SearchResultSet $matches
	 * @return string[]
	 */
	private function extractTitleStrings( SearchResultSet $matches ) {
		$strings = [];
		$matches->rewind();
		$result = $matches->next();
		while ( $result ) {
			$strings[] = (string)$result->getTitle();
			$result = $matches->next();
		}
		// not everything rewinds before working through the matches, so
		// be nice and rewind it for them.
		$matches->rewind();

		return $strings;
	}

	/**
	 * @param RequestLog $log The request to build a log message about
	 * @param array $context Request specific log variables from RequestLog::getLogVariables()
	 * @return string a PSR-3 compliant message describing $context
	 */
	private function buildLogMessage( RequestLog $log, array $context ) {
		$message = $log->getDescription();
		$message .= " against {index} took {tookMs} millis";
		if ( isset( $context['elasticTookMs'] ) ) {
			$message .= " and {elasticTookMs} Elasticsearch millis";
			if ( isset( $context['elasticTook2PassMs'] ) && $context['elasticTook2PassMs'] >= 0 ) {
				$message .= " (with 2nd pass: {elasticTook2PassMs} ms)";
			}
		}
		if ( isset( $context['hitsTotal'] ) ) {
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
	 * Enforce all avro-specified type constraints on CirrusSearchHit and limit
	 * the number of results to the maximum specified. The defaults should
	 * match the defaults specified in CirrusSearchRequestSet.idl
	 * (mediawiki-event-schemas repository)
	 *
	 * @param array[] $hits
	 * @return array[]
	 */
	private function encodeHits( array $hits ) {
		$result = [];
		foreach ( array_slice( $hits, 0, self::LOG_MAX_RESULTS )  as $hit ) {
			$result[] = [
				'title' => isset( $hit['title'] ) ? (string)$hit['title'] : '',
				'index' => isset( $hit['index'] ) ? (string)$hit['index'] : '',
				// @todo this can be a string, and should be docId
				'pageId' => isset( $hit['pageId'] ) ? (int)$hit['pageId'] : -1,
				'score' => isset( $hit['score'] ) ? (float)$hit['score'] : -1,
				'profileName' => isset( $hit['profileName'] ) ? (string)$hit['profileName'] : '',
			];
		}

		return $result;
	}

	/**
	 * Note that this is only accurate for hhvm and php-fpm
	 * since they close the request to the user before running
	 * deferred updates.
	 *
	 * @return int The number of ms the php request took
	 */
	private function getPhpRequestTookMs() {
		$timing = \RequestContext::getMain()->getTiming();
		$startMark = $timing->getEntryByName( 'requestStart' );
		$endMark  = $timing->getEntryByName( 'requestShutdown' );
		if ( $startMark && $endMark ) {
			// should always work, but Timing can return null so
			// fallbacks are provided.
			$tookS = $endMark['startTime'] - $startMark['startTime'];
		} elseif ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			// php >= 5.4
			$tookS = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
		} else {
			// php 5.3
			$tookS = microtime( true ) - $_SERVER['REQUEST_TIME'];
		}

		return intval( 1000 * $tookS );
	}
}
