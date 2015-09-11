<?php

namespace CirrusSearch;

use Elastica;
use CirrusSearch;
use CirrusSearch\BuildDocument\SuggestBuilder;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\SearchSuggestion;
use CirrusSearch\Search\SearchSuggestionSet;
use ConfigFactory;
use MediaWiki\Logger\LoggerFactory;
use Title;
use User;
use Elastica\Request;
use Elastica\Exception\ResponseException;

/**
 * Performs search as you type queries using Completion Suggester.
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

/**
 * Completion Suggester Searcher
 *
 * NOTES:
 * The CompletionSuggester is built on top of the ElasticSearch Completion
 * Suggester.
 * (https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-completion.html).
 *
 * This class is used at query time, see
 * CirrusSearch\BuildDocument\SuggestBuilder for index time logic.
 *
 * Document model: Cirrus documents are indexed with 2 suggestions:
 *
 * 1. The title suggestion (and close redirects).
 * This helps to avoid displaying redirects with typos (e.g. Albert Enstein,
 * Unietd States) where we make the assumption that if the redirect is close
 * enough it's likely a typo and it's preferable to display the canonical title.
 * This decision is made at index-time in SuggestBuilder::extractTitleAndSimilarRedirects.
 *
 * 2. The redirect suggestions
 * Because the same canonical title can be returned twice we support fetch_limit_factor
 * in suggest profiles to fetch more than what the use asked. Because the list of redirects
 * can be very large we cannot store all of them in the index (see limitations). We run a second
 * pass query on the main cirrus index to fetch them, then we try to detect which one is the closest
 * to the user query (see Util::chooseBestRedirect).
 *
 * LIMITATIONS:
 * A number of hacks are required in Cirrus to workaround some limitations in
 * the elasticsearch completion suggester implementation:
 * - It is a _suggest API, unlike classic "query then fetch" there is no fetch
 *   phase here.
 * - Payloads are stored in memory within the FST: we try to avoid them, but
 *   this forces us to implement a second pass query to fetch redirect titles
 *   from the cirrus main index.
 * - Fuzzy suggestions are ranked by index-time score: we allow to set
 *   'discount' param in the suggest profile (profiles/SuggestProfiles.php). The
 *   default profile includes a fuzzy and non-fuzzy suggestion query. This is to
 *   avoid having fuzzy suggestions ranked higher than exact suggestion.
 * - The suggestion string cannot be expanded to more than 255 strings at
 *   index time: we limit the number of generated tokens in the analysis config
 *   (see includes/Maintenance/SuggesterAnalysisConfigBuilder.php) but we can't
 *   workaround this problem for geosuggestion  (suggestions will be prepended by
 *   geohash prefixes, one per precision step)
 *
 * @todo: investigate new features in elasticsearch completion suggester v2 to remove
 * some workarounds (https://github.com/elastic/elasticsearch/issues/10746).
 */
class CompletionSuggester extends ElasticsearchIntermediary {
	/**
	 * @var string term to search.
	 */
	private $term;

	/**
	 * @var integer maximum number of result
	 */
	private $limit;

	/**
	 * @var string index base name to use
	 */
	private $indexBaseName;

	/**
	 * Search environment configuration
	 * @var SearchConfig
	 * Specified as public because of closures. When we move to non-anicent PHP version, can be made protected.
	 */
	public $config;

	/**
	 * @var SearchContext
	 */
	private $searchContext;

	/**
	 * Constructor
	 * @param int $limit Limit the results to this many
	 * @param SearchConfig Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|boolean $index Base name for index to search from, defaults to wfWikiId()
	 */
	public function __construct( Connection $conn, $limit, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false ) {

		if ( is_null( $config ) ) {
			// @todo connection has an embeded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ) );
		$this->config = $config;
		$this->limit = $limit;
		$this->indexBaseName = $index ?: $config->getWikiId();
		$this->searchContext = new SearchContext( $this->config, $namespaces );
	}

	/**
	 * Produce a set of completion suggestions for text using _suggest
	 * See https://www.elastic.co/guide/en/elasticsearch/reference/1.6/search-suggesters-completion.html
	 *
	 * WARNING: experimental API
	 *
	 * @param string $text Search term
	 * @param array $context
	 * @return Status
	 */
	public function suggest( $text, $context = null ) {
		// Do not remove spaces at the end, the user might tell us he finished writing a word
		$this->term = ltrim( $text );

		if ( mb_strlen( $this->term ) > SuggestBuilder::MAX_INPUT_LENGTH ) {
			// Trim the query otherwise we won't find results
			$this->term = mb_substr( $this->term, 0, SuggestBuilder::MAX_INPUT_LENGTH );
		}

		$suggest = array( 'text' => $text );
		$queryLen = mb_strlen( trim( $text ) ); // Avoid cheating with spaces
		$queryType = "comp_suggest";

		$profiles = $this->config->get( 'CirrusSearchCompletionSettings' );
		if ( $context != null && isset( $context['geo']['lat'] ) && isset( $context['geo']['lon'] )
			&& is_numeric( $context['geo']['lat'] ) && is_numeric( $context['geo']['lon'] )
		) {
			$profiles = $this->prepareGeoContextSuggestProfiles( $context );
			$queryType = "comp_suggest_geo";
		}

		foreach ( $profiles as $name => $config ) {
			if ( $config['min_query_len'] > $queryLen ) {
				continue;
			}
			if ( isset( $config['max_query_len'] ) && $queryLen > $config['max_query_len'] ) {
				continue;
			}
			$field = $config['field'];
			$suggest[$name] = array(
				'completion' => array(
					'field' => $field,
					'size' => $this->limit * $config['fetch_limit_factor']
				)
			);
			if ( isset( $config['fuzzy'] ) ) {
				$suggest[$name]['completion']['fuzzy'] = $config['fuzzy'];
			}
			if ( isset( $config['context'] ) ) {
				$suggest[$name]['completion']['context'] = $config['context'];
			}
		}

		$queryOptions = array();
		$queryOptions[ 'timeout' ] = $this->config->getElement( 'CirrusSearchSearchShardTimeout', 'default' );
		$this->connection->setTimeout( $queryOptions[ 'timeout' ] );

		$index = $this->connection->getIndex( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE );
		$logContext = array(
			'query' => $text,
			'queryType' => $queryType,
		);
		$searcher = $this;
		$limit = $this->limit;
		$result = Util::doPoolCounterWork(
			'CirrusSearch-Search',
			$this->user,
			function() use( $searcher, $index, $suggest, $logContext, $queryOptions,
					$profiles, $text , $limit ) {
				$description = "{queryType} search for '{query}'";
				$searcher->start( $description, $logContext );
				try {
					$result = $index->request( "_suggest", Request::POST, $suggest, $queryOptions );
					if( $result->isOk() ) {
						$result = $searcher->postProcessSuggest( $text, $result,
							$profiles, $limit );
						return $searcher->success( $result );
					}
					return $result;
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			}
		);
		return $result;
	}

	/**
	 * prepare the list of suggest requests used for geo context suggestions
	 * This method will merge $this->config->get( 'CirrusSearchCompletionSettings and
	 * $this->config->get( 'CirrusSearchCompletionGeoContextSettings
	 * @param array $context user's geo context
	 * @return array of suggest request profiles
	 */
	private function prepareGeoContextSuggestProfiles( $context ) {
		$profiles = array();
		foreach ( $this->config->get( 'CirrusSearchCompletionGeoContextSettings' ) as $geoname => $geoprof ) {
			foreach ( $this->config->get( 'CirrusSearchCompletionSettings' ) as $sugname => $sugprof ) {
				if ( !in_array( $sugname, $geoprof['with'] ) ) {
					continue;
				}
				$profile = $sugprof;
				$profile['field'] .= $geoprof['field_suffix'];
				$profile['discount'] *= $geoprof['discount'];
				$profile['context'] = array(
					'location' => array(
						'lat' => $context['geo']['lat'],
						'lon' => $context['geo']['lon'],
						'precision' => $geoprof['precision']
					)
				);
				$profiles["$sugname-$geoname"] = $profile;
			}
		}
		return $profiles;
	}

	/**
	 * merge top level multi-queries and resolve returned pageIds into Title objects.
	 *
	 * WARNING: experimental API
	 *
	 * @param string $query the user query
	 * @param \Elastica\Response $response Response from elasticsearch _suggest api
	 * @param array $profiles the suggestion profiles
	 * @param int $limit Maximum suggestions to return, -1 for unlimited
	 * @return SearchSuggestionSet a set of Suggestions
	 */
	protected function postProcessSuggest( $query, \Elastica\Response $response, $profiles, $limit = -1 ) {
		$this->logContext['elasticTookMs'] = intval( $response->getQueryTime() * 1000 );
		$data = $response->getData();
		unset( $data['_shards'] );

		$suggestions = array();
		foreach ( $data as $name => $results  ) {
			$discount = $profiles[$name]['discount'];
			foreach ( $results  as $suggested ) {
				foreach ( $suggested['options'] as $suggest ) {
					$output = SuggestBuilder::decodeOutput( $suggest['text'] );
					if ( $output === null ) {
						// Ignore broken output
						continue;
					}
					$pageId = $output['id'];
					$type = $output['type'];

					$score = $discount * $suggest['score'];
					if ( !isset( $suggestions[$pageId] ) ||
						$score > $suggestions[$pageId]->getScore()
					) {
						$suggestion = new SearchSuggestion( null, null, $score, null, $pageId );
						// If it's a title suggestion we have the text
						if ( $type === SuggestBuilder::TITLE_SUGGESTION ) {
							$suggestion->setText( $output['text'] );
						}
						$suggestions[$pageId] = $suggestion;
					}
				}
			}
		}

		// simply sort by existing scores
		uasort( $suggestions, function ( $a, $b ) {
			return $b->getScore() - $a->getScore();
		} );

		$this->logContext['hitsTotal'] = count( $suggestions );

		if ( $limit > 0 ) {
			$suggestions = array_slice( $suggestions, 0, $limit, true );
		}

		$this->logContext['hitsReturned'] = count( $suggestions );
		$this->logContext['hitsOffset'] = 0;

		// we must fetch redirect data for redirect suggestions
		$missingText = array();
		foreach ( $suggestions as $id => $suggestion ) {
			if ( $suggestion->getText() === null ) {
				$missingText[] = $id;
			}
		}

		if ( !empty ( $missingText ) ) {
			// Experimental.
			//
			// Second pass query to fetch redirects.
			// It's not clear if it's the best option, this will slowdown the whole query
			// when we hit a redirect suggestion.
			// Other option would be to encode redirects as a payload resulting in a
			// very big index...

			// XXX: we support only the content index
			$type = $this->connection->getPageType( $this->indexBaseName, Connection::CONTENT_INDEX_TYPE );
			// NOTE: we are already in a poolCounterWork
			// Multi get is not supported by elastica
			$redirResponse = null;
			try {
				$redirResponse = $type->request( '_mget', 'GET',
					array( 'ids' => $missingText ),
					array( '_source_include' => 'redirect' ) );
				if ( $redirResponse->isOk() ) {
					$this->logContext['elasticTook2PassMs'] = intval( $redirResponse->getQueryTime() * 1000 );
					$docs = $redirResponse->getData();
					$docs = $docs['docs'];
					foreach ( $docs as $doc ) {
						$id = $doc['_id'];
						if ( !isset( $doc['_source']['redirect'] )
							|| empty( $doc['_source']['redirect'] )
						) {
							continue;
						}
						$text = Util::chooseBestRedirect( $query, $doc['_source']['redirect'] );
						$suggestions[$id]->setText( $text );
					}
				} else {
					LoggerFactory::getInstance( 'CirrusSearch' )->warning(
						'Unable to fetch redirects for suggestion {query} with results {ids} : {error}',
						array( 'query' => $query,
							'ids' => serialize( $missingText ),
							'error' => $redirResponse->getError() ) );
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Unable to fetch redirects for suggestion {query} with results {ids} : {error}',
					array( 'query' => $query,
						'ids' => serialize( $missingText ),
						'error' => $this->extractMessage( $e ) ) );
			}
		}

		$retval = array();
		foreach ( $suggestions as $suggestion ) {
			if ( $suggestion->getText() === null ) {
				// We were unable to find a text to display
				// Maybe a page with redirects when we built the suggester index
				// but now without redirects?
				continue;
			}
			// Populate the SearchSuggestion object
			$suggestion->setSuggestedTitle( Title::makeTitle( 0, $suggestion->getText() ), true );
			$retval[] = $suggestion;
		}

		return new SearchSuggestionSet( $retval );
	}

	/**
	 * Set the max number of results to extract.
	 * @param int $limit
	 */
	public function setLimit( $limit ) {
		$this->limit = $limit;
	}
}
