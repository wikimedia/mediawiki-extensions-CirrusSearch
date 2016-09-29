<?php

namespace CirrusSearch;

use Elastica;
use Elastica\Request;
use CirrusSearch;
use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use CirrusSearch\Search\SearchContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Logger\LoggerFactory;
use SearchSuggestion;
use SearchSuggestionSet;
use Status;
use UsageException;
use User;

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
	const VARIANT_EXTRA_DISCOUNT = 0.0001;
	/**
	 * @var string term to search.
	 */
	private $term;

	/**
	 * @var string[]|null search variants
	 */
	private $variants;

	/**
	 * Currently very limited (see LIMITATIONS) and only works
	 * for geo context
	 * @var array|null context for contextualized suggestions
	 */
	private $context;

	/**
	 * @var integer maximum number of result
	 */
	private $limit;

	/**
	 * @var integer offset
	 */
	private $offset;

	/**
	 * @var string index base name to use
	 */
	private $indexBaseName;

	/**
	 * Search environment configuration
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var string Query type (comp_suggest_geo or comp_suggest)
	 */
	public $queryType;

	/**
	 * @var SearchContext
	 */
	private $searchContext;

	private $settings;

	/**
	 * Constructor
	 * @param Connection $conn
	 * @param int $limit Limit the results to this many
	 * @param int $offset the offset
	 * @param SearchConfig $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|boolean $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 * @param string|null $profileName
	 */
	public function __construct( Connection $conn, $limit, $offset = 0, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false, $profileName = null ) {

		if ( is_null( $config ) ) {
			// @todo connection has an embedded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ) );
		$this->config = $config;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->indexBaseName = $index ?: $config->get( SearchConfig::INDEX_BASE_NAME );
		$this->searchContext = new SearchContext( $this->config, $namespaces );

		if ( $profileName == null ) {
			$profileName = $this->config->get( 'CirrusSearchCompletionSettings' );
		}
		$this->settings = $this->config->getElement( 'CirrusSearchCompletionProfiles', $profileName );
	}

	/**
	 * @param string $search
	 * @throws UsageException
	 */
	private function checkRequestLength( $search ) {
		$requestLength = mb_strlen( $search );
		if ( $requestLength > Searcher::MAX_TITLE_SEARCH ) {
			throw new UsageException( 'Prefix search request was longer than the maximum allowed length.' .
					" ($requestLength > " . Searcher::MAX_TITLE_SEARCH . ')', 'request_too_long', 400 );
		}
	}

	/**
	 * Produce a set of completion suggestions for text using _suggest
	 * See https://www.elastic.co/guide/en/elasticsearch/reference/1.6/search-suggesters-completion.html
	 *
	 * WARNING: experimental API
	 *
	 * @param string $text Search term
	 * @param string[]|null $variants Search term variants
	 * (usually issued from $wgContLang->autoConvertToAllVariants( $text ) )
	 * @param array $context
	 * @return Status
	 */
	public function suggest( $text, $variants = null, $context = null ) {
		// If the offset requested is greater than the hard limit
		// allowed we will always return an empty set so let's do it
		// asap.
		if ( $this->offset >= $this->getHardLimit() ) {
			return Status::newGood( SearchSuggestionSet::emptySuggestionSet() );
		}

		$this->checkRequestLength( $text );
		$this->setTermAndVariants( $text, $variants );
		$this->context = $context;

		list( $profiles, $suggest ) = $this->buildQuery();
		$queryOptions = [
			'timeout' => $this->config->getElement( 'CirrusSearchSearchShardTimeout', 'default' ),
		];
		$this->connection->setTimeout( $queryOptions[ 'timeout' ] );

		$index = $this->connection->getIndex( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE );
		$logContext = [
			'query' => $text,
			'queryType' => $this->queryType,
		];
		$result = Util::doPoolCounterWork(
			'CirrusSearch-Completion',
			$this->user,
			function() use( $index, $suggest, $logContext, $queryOptions,
					$profiles, $text ) {
				$description = "{queryType} search for '{query}'";
				$this->start( $description, $logContext );
				$this->logContext['timing']['start'] = microtime( true );
				try {
					$result = $index->request( "_suggest", Request::POST, $suggest, $queryOptions );
					$this->logContext['timing']['end-comp-req'] = microtime( true );
					if( $result->isOk() ) {
						$result = $this->postProcessSuggest( $result, $profiles );
					}
					$this->logContext['timing']['done'] = microtime( true );
					return $this->success( $result );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			}
		);
		return $result;
	}

	/**
	 * protected for tests
	 *
	 * @param string $term
	 * @param string[]|null $variants
	 */
	protected function setTermAndVariants( $term, array $variants = null ) {
		$this->term = $term;
		if ( empty( $variants ) ) {
			$this->variants = null;
			return;
		}
		$variants = array_diff( array_unique( $variants ), [ $term ] );
		if ( empty( $variants ) ) {
			$this->variants = null;
		} else {
			$this->variants = $variants;
		}
	}

	/**
	 * Builds the suggest queries and profiles.
	 * Use with list( $profiles, $suggest ).
	 * @return array the profiles and suggest queries
	 */
	protected function buildQuery() {
		if ( mb_strlen( $this->term ) > SuggestBuilder::MAX_INPUT_LENGTH ) {
			// Trim the query otherwise we won't find results
			$this->term = mb_substr( $this->term, 0, SuggestBuilder::MAX_INPUT_LENGTH );
		}

		$queryLen = mb_strlen( trim( $this->term ) ); // Avoid cheating with spaces
		$this->queryType = "comp_suggest";

		$profiles = $this->settings;
		if ( $this->context != null && isset( $this->context['geo']['lat'] )
			&& isset( $this->context['geo']['lon'] ) && is_numeric( $this->context['geo']['lat'] )
			&& is_numeric( $this->context['geo']['lon'] )
		) {
			$profiles = $this->prepareGeoContextSuggestProfiles();
			$this->queryType = "comp_suggest_geo";
		}

		$suggest = $this->buildSuggestQueries( $profiles, $this->term, $queryLen );

		// Handle variants, update the set of profiles and suggest queries
		if ( !empty( $this->variants ) ) {
			list( $addProfiles, $addSuggest ) = $this->handleVariants( $profiles, $queryLen );
			$profiles += $addProfiles;
			$suggest += $addSuggest;
		}
		return [ $profiles, $suggest ];
	}

	/**
	 * Builds a set of suggest query by reading the list of profiles
	 * @param array $profiles
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return array a set of suggest queries ready to for elastic
	 */
	protected function buildSuggestQueries( array $profiles, $query, $queryLen ) {
		$suggest = [];
		foreach($profiles as $name => $config) {
			$sugg = $this->buildSuggestQuery( $config, $query, $queryLen );
			if(!$sugg) {
				continue;
			}
			$suggest[$name] = $sugg;
		}
		return $suggest;
	}

	/**
	 * Builds a suggest query from a profile
	 * @param array $config Profile
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return array|null suggest query ready to for elastic or null
	 */
	protected function buildSuggestQuery( array $config, $query, $queryLen ) {
		// Do not remove spaces at the end, the user might tell us he finished writing a word
		$query = ltrim( $query );
		if ( $config['min_query_len'] > $queryLen ) {
			return null;
		}
		if ( isset( $config['max_query_len'] ) && $queryLen > $config['max_query_len'] ) {
			return null;
		}
		$field = $config['field'];
		$limit = $this->getHardLimit();
		$suggest = [
			'text' => $query,
			'completion' => [
				'field' => $field,
				'size' => $limit * $config['fetch_limit_factor']
			]
		];
		if ( isset( $config['fuzzy'] ) ) {
			$suggest['completion']['fuzzy'] = $config['fuzzy'];
		}
		if ( isset( $config['context'] ) ) {
			$suggest['completion']['context'] = $config['context'];
		}
		return $suggest;
	}

	/**
	 * Update the suggest queries and return additional profiles flagged the 'fallback' key
	 * with a discount factor = originalDiscount * 0.0001/(variantIndex+1).
	 * @param array $profiles the default profiles
	 * @param int $queryLen the original query length
	 * @return array new variant profiles
	 */
	 protected function handleVariants( array $profiles, $queryLen ) {
		$variantIndex = 0;
		$allVariantProfiles = [];
		$allSuggestions = [];
		foreach( $this->variants as $variant ) {
			$variantIndex++;
			foreach ( $profiles as $name => $profile ) {
				$variantProfName = $name . '-variant-' . $variantIndex;
				$allVariantProfiles[$variantProfName] = $this->buildVariantProfile( $profile, self::VARIANT_EXTRA_DISCOUNT/$variantIndex );
				$allSuggestions[$variantProfName] = $this->buildSuggestQuery(
							$allVariantProfiles[$variantProfName], $variant, $queryLen
						);
			}
		}
		return [ $allVariantProfiles, $allSuggestions ];
	}

	/**
	 * Creates a copy of $profile[$name] with a custom '-variant-SEQ' suffix.
	 * And applies an extra discount factor of 0.0001.
	 * The copy is added to the profiles container.
	 * @param array $profile profile to copy
	 * @param float $extraDiscount extra discount factor to rank variant suggestion lower.
	 * @return array
	 */
	protected function buildVariantProfile( array $profile, $extraDiscount = 0.0001 ) {
		// mark the profile as a fallback query
		$profile['fallback'] = true;
		$profile['discount'] *= $extraDiscount;
		return $profile;
	}

	/**
	 * prepare the list of suggest requests used for geo context suggestions
	 * This method will merge completion settings with
	 * $this->config->get( 'CirrusSearchCompletionGeoContextSettings' )
	 * @return array of suggest request profiles
	 */
	private function prepareGeoContextSuggestProfiles() {
		$profiles = [];
		foreach ( $this->config->get( 'CirrusSearchCompletionGeoContextSettings' ) as $geoname => $geoprof ) {
			foreach ( $this->settings as $sugname => $sugprof ) {
				if ( !in_array( $sugname, $geoprof['with'] ) ) {
					continue;
				}
				$profile = $sugprof;
				$profile['field'] .= $geoprof['field_suffix'];
				$profile['discount'] *= $geoprof['discount'];
				$profile['context'] = [
					'location' => [
						'lat' => $this->context['geo']['lat'],
						'lon' => $this->context['geo']['lon'],
						'precision' => $geoprof['precision']
					]
				];
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
	 * @param \Elastica\Response $response Response from elasticsearch _suggest api
	 * @param array $profiles the suggestion profiles
	 * @return SearchSuggestionSet a set of Suggestions
	 */
	protected function postProcessSuggest( \Elastica\Response $response, $profiles ) {
		$this->logContext['elasticTookMs'] = intval( $response->getQueryTime() * 1000 );
		$data = $response->getData();
		unset( $data['_shards'] );

		$this->logContext['timing']['mark1'] = microtime( true );
		$limit = $this->getHardLimit();
		$suggestionsByDocId = [];
		$suggestionProfileByDocId = [];
		$hitsTotal = 0;
		foreach ( $data as $name => $results  ) {
			$discount = $profiles[$name]['discount'];
			foreach ( $results  as $suggested ) {
				$hitsTotal += count( $suggested['options'] );
				foreach ( $suggested['options'] as $suggest ) {
					$output = SuggestBuilder::decodeOutput( $suggest['text'] );
					if ( $output === null ) {
						// Ignore broken output
						continue;
					}
					$docId = $output['docId'];
					$type = $output['type'];

					$score = $discount * $suggest['score'];
					if ( !isset( $suggestionsByDocId[$docId] ) ||
						$score > $suggestionsByDocId[$docId]->getScore()
					) {
						$pageId = $this->config->makePageId( $docId );
						$suggestion = new SearchSuggestion( $score, null, null, $pageId );
						// If it's a title suggestion we have the text
						if ( $type === SuggestBuilder::TITLE_SUGGESTION ) {
							$suggestion->setText( $output['text'] );
						}
						$suggestionsByDocId[$docId] = $suggestion;
						$suggestionProfileByDocId[$docId] = $name;
					}
				}
			}
		}
		$this->logContext['timing']['mark2'] = microtime( true );

		// simply sort by existing scores
		uasort( $suggestionsByDocId, function ( SearchSuggestion $a, SearchSuggestion $b ) {
			return $b->getScore() - $a->getScore();
		} );

		$this->logContext['hitsTotal'] = $hitsTotal;

		$suggestionsByDocId = $this->offset < $limit
			? array_slice( $suggestionsByDocId, $this->offset, $limit - $this->offset, true )
			: [];

		$this->logContext['hitsReturned'] = count( $suggestionsByDocId );
		$this->logContext['hitsOffset'] = $this->offset;

		// we must fetch redirect data for redirect suggestions
		$missingTextDocIds = [];
		foreach ( $suggestionsByDocId as $docId => $suggestion ) {
			if ( $suggestion->getText() === null ) {
				$missingTextDocIds[] = $docId;
			}
		}

		if ( !empty ( $missingTextDocIds ) ) {
			$this->logContext['timing']['mark3'] = microtime( true );
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
					[ 'ids' => $missingTextDocIds ],
					[ '_source_include' => 'redirect' ] );
				$this->logContext['timing']['mark4'] = microtime( true );
				if ( $redirResponse->isOk() ) {
					$this->logContext['elasticTook2PassMs'] = intval( $redirResponse->getQueryTime() * 1000 );
					$docs = $redirResponse->getData();
					foreach ( $docs['docs'] as $doc ) {
						if ( empty( $doc['_source']['redirect'] ) ) {
							continue;
						}
						// We use the original query, we should maybe use the variant that generated this result?
						$text = Util::chooseBestRedirect( $this->term, $doc['_source']['redirect'] );
						if( !empty( $suggestionsByDocId[$doc['_id']] ) ) {
							$suggestionsByDocId[$doc['_id']]->setText( $text );
						}
					}
				} else {
					LoggerFactory::getInstance( 'CirrusSearch' )->warning(
						'Unable to fetch redirects for suggestion {query} with results {ids} : {error}',
						[ 'query' => $this->term,
							'ids' => serialize( $missingText ),
							'error' => $redirResponse->getError() ] );
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$error = ElasticaErrorHandler::extractFullError( $e );
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Unable to fetch redirects for suggestion {query} with results {ids}. {error_type}: {error_reason}',
					[
						'query' => $this->term,
						'ids' => serialize( $missingText ),
						'error_type' => $error['type'],
						'error_reason' => $error['reason'],
					]
				);
			}
			$this->logContext['timing']['mark5'] = microtime( true );
		}

		$this->logContext['timing']['mark6'] = microtime( true );
		$finalResults = array_filter(
			$suggestionsByDocId,
			function ( SearchSuggestion $suggestion ) {
				// text should be not empty for suggestions
				return $suggestion->getText() != null;
			}
		);

		$this->logContext['hits'] = [];
		$indexName = $this->connection->getIndex( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE )->getName();
		$maxScore = 0;
		foreach ( $finalResults as $docId => $suggestion ) {
			$title = $suggestion->getSuggestedTitle();
			$pageId = $suggestion->getSuggestedTitleID() ?: -1;
			$maxScore = max( $maxScore, $suggestion->getScore() );
			$this->logContext['hits'][] = [
				// This *must* match the names and types of the CirrusSearchHit
				// record in the CirrusSearchRequestSet logging channel avro schema.
				'title' => $title ? (string) $title : $suggestion->getText(),
				'index' => $indexName,
				'pageId' => (int) $pageId,
				'profileName' => isset( $suggestionProfileByDocId[$docId] )
					? $suggestionProfileByDocId[$docId]
					: "",
				'score' => $suggestion->getScore(),
			];
		}
		$this->logContext['maxScore'] = $maxScore;
		$this->logContext['timing']['mark7'] = microtime( true );

		return new SearchSuggestionSet( $finalResults );
	}

	/**
	 * Set the max number of results to extract.
	 * @param int $limit
	 */
	public function setLimit( $limit ) {
		$this->limit = $limit;
	}

	/**
	 * Set the offset
	 * @param int $offset
	 */
	public function setOffset( $offset ) {
		$this->offset = $offset;
	}

	/**
	 * Get the hard limit
	 * The completion api does not supports offset we have to add a hack
	 * here to work around this limitation.
	 * To avoid ridiculously large queries we set also a hard limit.
	 * Note that this limit will be changed by fetch_limit_factor set to 2 or 1.5
	 * depending on the profile.
	 * @return int the number of results to fetch from elastic
	 */
	private function getHardLimit() {
		$limit = $this->limit + $this->offset;
		$hardLimit = $this->config->get( 'CirrusSearchCompletionSuggesterHardLimit' );
		if ( $hardLimit === NULL ) {
			$hardLimit = 50;
		}
		if ( $limit > $hardLimit ) {
			return $hardLimit;
		}
		return $limit;
	}
}
