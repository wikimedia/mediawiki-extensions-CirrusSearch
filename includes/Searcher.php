<?php

namespace CirrusSearch;

use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\ResultsType;
use CirrusSearch\Search\RescoreBuilder;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Query\FullTextQueryBuilder;
use Language;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use ObjectCache;
use SearchResultSet;
use Status;
use Title;
use UsageException;
use User;

/**
 * Performs searches using Elasticsearch.  Note that each instance of this class
 * is single use only.
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
class Searcher extends ElasticsearchIntermediary {
	const SUGGESTION_HIGHLIGHT_PRE = '<em>';
	const SUGGESTION_HIGHLIGHT_POST = '</em>';
	const HIGHLIGHT_PRE = '<span class="searchmatch">';
	const HIGHLIGHT_POST = '</span>';
	const HIGHLIGHT_REGEX = '/<span class="searchmatch">.*?<\/span>/';

	/**
	 * Maximum title length that we'll check in prefix and keyword searches.
	 * Since titles can be 255 bytes in length we're setting this to 255
	 * characters.
	 */
	const MAX_TITLE_SEARCH = 255;

	/**
	 * Maximum length, in characters, allowed in queries sent to searchText.
	 */
	const MAX_TEXT_SEARCH = 300;

	/**
	 * Maximum offset + limit depth allowed. As in the deepest possible result
	 * to return. Too deep will cause very slow queries. 10,000 feels plenty
	 * deep. This should be <= index.max_result_window in elasticsearch.
	 */
	const MAX_OFFSET_LIMIT = 10000;

	/**
	 * @var integer search offset
	 */
	private $offset;

	/**
	 * @var integer maximum number of result
	 */
	private $limit;

	/**
	 * @var Language language of the wiki
	 */
	private $language;

	/**
	 * @var ResultsType|null type of results.  null defaults to FullTextResultsType
	 */
	private $resultsType;
	/**
	 * @var string sort type
	 */
	private $sort = 'relevance';

	/**
	 * @var string index base name to use
	 */
	protected $indexBaseName;

	/**
	 * @var Escaper escapes queries
	 */
	private $escaper;

	/**
	 * @var boolean just return the array that makes up the query instead of searching
	 */
	private $returnQuery = false;

	/**
	 * @var boolean return raw Elasticsearch result instead of processing it
	 */
	private $returnResult = false;

	/**
	 * @var boolean return explanation with results
	 */
	private $returnExplain = false;

	/**
	 * Search environment configuration
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * @var SearchContext
	 */
	protected $searchContext;

	/**
	 * Constructor
	 * @param Connection $conn
	 * @param int $offset Offset the results by this much
	 * @param int $limit Limit the results to this many
	 * @param SearchConfig|null $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|boolean $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 */
	public function __construct( Connection $conn, $offset, $limit, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false ) {

		if ( is_null( $config ) ) {
			// @todo connection has an embedded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ), $config->get( 'CirrusSearchExtraBackendLatency' ) );
		$this->config = $config;
		$this->offset = $offset;
		if ( $offset + $limit > self::MAX_OFFSET_LIMIT ) {
			$this->limit = self::MAX_OFFSET_LIMIT - $offset;
		} else {
			$this->limit = $limit;
		}
		$this->indexBaseName = $index ?: $config->get( SearchConfig::INDEX_BASE_NAME );
		$this->language = $config->get( 'ContLang' );
		$this->escaper = new Escaper( $config->get( 'LanguageCode' ), $config->get( 'CirrusSearchAllowLeadingWildcard' ) );
		$this->searchContext = new SearchContext( $this->config, $namespaces );
	}

	/**
	 * @param ResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->resultsType = $resultsType;
	}

	/**
	 * @param boolean $returnQuery just return the array that makes up the query instead of searching
	 */
	public function setReturnQuery( $returnQuery ) {
		$this->returnQuery = $returnQuery;
	}

	/**
	 * @param boolean $dumpResult return raw Elasticsearch result instead of processing it
	 */
	public function setDumpResult( $dumpResult ) {
		$this->returnResult = $dumpResult;
	}

	/**
	 * @param boolean $returnExplain return query explanation
	 */
	public function setReturnExplain( $returnExplain ) {
		$this->returnExplain = $returnExplain;
	}

	/**
	 * Set the type of sort to perform.  Must be 'relevance', 'title_asc', 'title_desc'.
	 * @param string $sort sort type
	 */
	public function setSort( $sort ) {
		$this->sort = $sort;
	}

	/**
	 * Should this search limit results to the local wiki?  If not called the default is false.
	 * @param boolean $limitSearchToLocalWiki should the results be limited?
	 */
	public function limitSearchToLocalWiki( $limitSearchToLocalWiki ) {
		$this->searchContext->setLimitSearchToLocalWiki( $limitSearchToLocalWiki );
	}

	/**
	 * Perform a "near match" title search which is pretty much a prefix match without the prefixes.
	 * @param string $term text by which to search
	 * @return Status status containing results defined by resultsType on success
	 */
	public function nearMatchTitleSearch( $term ) {
		$this->checkTitleSearchRequestLength( $term );

		$this->searchContext->setOriginalSearchTerm( $term );
		// Elasticsearch seems to have trouble extracting the proper terms to highlight
		// from the default query we make so we feed it exactly the right query to highlight.
		$highlightQuery = new \Elastica\Query\MultiMatch();
		$highlightQuery->setQuery( $term );
		$highlightQuery->setFields( [
			'title.near_match', 'redirect.title.near_match',
			'title.near_match_asciifolding', 'redirect.title.near_match_asciifolding',
		] );
		if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) ) {
			// Instead of using the highlight query we need to make one like it that uses the all_near_match field.
			$allQuery = new \Elastica\Query\MultiMatch();
			$allQuery->setQuery( $term );
			$allQuery->setFields( [ 'all_near_match', 'all_near_match.asciifolding' ] );
			$this->searchContext->addFilter( $allQuery );
		} else {
			$this->searchContext->addFilter( $highlightQuery );
		}
		$this->searchContext->setHighlightQuery( $highlightQuery );
		$this->searchContext->setSearchType( 'near_match' );

		return $this->searchOne();
	}

	/**
	 * Perform a prefix search.
	 * @param string $term text by which to search
	 * @return Status status containing results defined by resultsType on success
	 */
	public function prefixSearch( $term ) {
		$this->checkTitleSearchRequestLength( $term );
		$this->searchContext->setOriginalSearchTerm( $term );

		$this->searchContext->setSearchType( 'prefix' );
		if ( strlen( $term ) > 0 ) {
			if ( $this->config->get( 'CirrusSearchPrefixSearchStartsWithAnyWord' ) ) {
				$match = new \Elastica\Query\Match();
				$match->setField( 'title.word_prefix', [
					'query' => $term,
					'analyzer' => 'plain',
					'operator' => 'and',
				] );
				$this->searchContext->addFilter( $match );
			} else {
				// Elasticsearch seems to have trouble extracting the proper terms to highlight
				// from the default query we make so we feed it exactly the right query to highlight.
				$query = new \Elastica\Query\MultiMatch();
				$query->setQuery( $term );
				$weights = $this->config->get( 'CirrusSearchPrefixWeights' );
				$query->setFields( [
					'title.prefix^' . $weights[ 'title' ],
					'redirect.title.prefix^' . $weights[ 'redirect' ],
					'title.prefix_asciifolding^' . $weights[ 'title_asciifolding' ],
					'redirect.title.prefix_asciifolding^' . $weights[ 'redirect_asciifolding' ],
				] );
				$this->searchContext->setMainQuery( $query );
			}
		}

		/** @suppress PhanDeprecatedFunction */
		$this->searchContext->setBoostLinks( true );

		return $this->searchOne();
	}

	/**
	 * @param string $suggestPrefix prefix to be prepended to suggestions
	 */
	public function addSuggestPrefix( $suggestPrefix ) {
		$this->searchContext->addSuggestPrefix( $suggestPrefix );
	}

	/**
	 * Build full text search for articles with provided term. All the
	 * state is applied to $this->searchContext. The returned query
	 * builder can be used to build a degraded query if necessary.
	 *
	 * @param string $term term to search
	 * @param boolean $showSuggestion should this search suggest alternative searches that might be better?
	 * @return FullTextQueryBuilder
	 */
	protected function buildFullTextSearch( $term, $showSuggestion ) {
		// Convert the unicode character 'ideographic whitespace' into standard
		// whitespace. Cirrussearch treats them both as normal whitespace, but
		// the preceding isn't appropriately trimmed.
		// No searching for nothing! That takes forever!
		$term = trim( str_replace( "\xE3\x80\x80", " ", $term ) );
		if ( $term === '' ) {
			$this->searchContext->setResultsPossible( false );
		}

		$term = Util::stripQuestionMarks( $term, $this->config->get( 'CirrusSearchStripQuestionMarks' ) );
		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$this->searchContext->setSearchType( 'full_text' );

		$builderProfile = $this->config->get( 'CirrusSearchFullTextQueryBuilderProfile' );
		$builderSettings = $this->config->getElement( 'CirrusSearchFullTextQueryBuilderProfiles', $builderProfile );

		$qb = new $builderSettings['builder_class'](
			$this->config,
			$this->escaper,
			[
				// Handle morelike keyword (greedy). This needs to be the
				// very first item until combining with other queries
				// is worked out.
				new Query\MoreLikeFeature( $this->config, [$this, 'get'] ),
				// Handle title prefix notation (greedy)
				new Query\PrefixFeature(),
				// Handle prefer-recent keyword
				new Query\PreferRecentFeature( $this->config ),
				// Handle local keyword
				new Query\LocalFeature(),
				// Handle insource keyword using regex
				new Query\RegexInSourceFeature( $this->config ),
				// Handle neartitle, nearcoord keywords, and their boosted alternates
				new Query\GeoFeature(),
				// Handle boost-templates keyword
				new Query\BoostTemplatesFeature(),
				// Handle hastemplate keyword
				new Query\HasTemplateFeature(),
				// Handle linksto keyword
				new Query\LinksToFeature(),
				// Handle incategory keyword
				new Query\InCategoryFeature( $this->config ),
				// Handle non-regex insource keyword
				new Query\SimpleInSourceFeature( $this->escaper ),
				// Handle intitle keyword
				new Query\InTitleFeature( $this->escaper ),
				// inlanguage keyword
				new Query\LanguageFeature(),
				// File types
				new Query\FileTypeFeature(),
				// File numeric characteristics - size, resolution, etc.
				new Query\FileNumericFeature(),
			],
			$builderSettings['settings']
		);

		$showSuggestion = $showSuggestion && $this->offset == 0
			&& $this->config->get( 'CirrusSearchEnablePhraseSuggest' );
		$qb->build( $this->searchContext, $term, $showSuggestion );

		return $qb;
	}

	/**
	 * Search articles with provided term.
	 * @param string $term term to search
	 * @param boolean $showSuggestion should this search suggest alternative searches that might be better?
	 * @return Status
	 */
	public function searchText( $term, $showSuggestion ) {
		$checkLengthStatus = $this->checkTextSearchRequestLength( $term );
		$this->searchContext->setOriginalSearchTerm( $term );
		if ( !$checkLengthStatus->isOK() ) {
			return $checkLengthStatus;
		}

		$qb = $this->buildFullTextSearch( $term, $showSuggestion );

		$result = $this->searchOne();
		if ( !$result->isOK() && ElasticaErrorHandler::isParseError( $result ) ) {
			if ( $qb->buildDegraded( $this->searchContext ) ) {
				// If that doesn't work we're out of luck but it should.  There no guarantee it'll work properly
				// with the syntax we've built above but it'll do _something_ and we'll still work on fixing all
				// the parse errors that come in.
				$result = $this->searchOne();
			}
		}

		return $result;
	}

	/**
	 * Get the page with $docId.  Note that the result is a status containing _all_ pages found.
	 * It is possible to find more then one page if the page is in multiple indexes.
	 * @param string[] $docIds array of document ids
	 * @param string[]|true|false $sourceFiltering source filtering to apply
	 * @return Status containing pages found, containing an empty array if not found,
	 *    or an error if there was an error
	 */
	public function get( array $docIds, $sourceFiltering ) {
		$indexType = $this->connection->pickIndexTypeForNamespaces(
			$this->searchContext->getNamespaces()
		);

		// The worst case would be to have all ids duplicated in all available indices.
		// We set the limit accordingly
		$size = count ( $this->connection->getAllIndexSuffixesForNamespaces(
			$this->searchContext->getNamespaces()
		));
		$size *= count( $docIds );

		return Util::doPoolCounterWork(
			$this->getPoolCounterType(),
			$this->user,
			function() use ( $docIds, $sourceFiltering, $indexType, $size ) {
				try {
					$this->startNewLog( 'get of {indexType}.{docIds}', 'get', [
						'indexType' => $indexType,
						'docIds' => $docIds,
					] );
					// Shard timeout not supported on get requests so we just use the client side timeout
					$this->connection->setTimeout( $this->getTimeout() );
					// We use a search query instead of _get/_mget, these methods are
					// theorically well suited for this kind of job but they are not
					// supported on aliases with multiple indices (content/general)
					$pageType = $this->connection->getPageType( $this->indexBaseName, $indexType );
					$query = new \Elastica\Query( new \Elastica\Query\Ids( null, $docIds ) );
					$query->setParam( '_source', $sourceFiltering );
					$query->addParam( 'stats', 'get' );
					// We ignore limits provided to the searcher
					// otherwize we could return fewer results than
					// the ids requested.
					$query->setFrom( 0 );
					$query->setSize( $size );
					$resultSet = $pageType->search( $query, [ 'search_type' => 'query_then_fetch' ] );
					return $this->success( $resultSet->getResults() );
				} catch ( \Elastica\Exception\NotFoundException $e ) {
					// NotFoundException just means the field didn't exist.
					// It is up to the caller to decide if that is an error.
					return $this->success( [] );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			});
	}

	/**
	 * @param string $name
	 * @return Status
	 */
	public function findNamespace( $name ) {
		return Util::doPoolCounterWork(
			'CirrusSearch-NamespaceLookup',
			$this->user,
			function() use ( $name ) {
				try {
					$this->startNewLog( 'lookup namespace for {namespaceName}', 'namespace', [
						'namespaceName' => $name,
						'query' => $name,
					] );
					$queryOptions = [
						'search_type' => 'query_then_fetch',
						'timeout' => $this->getTimeout(),
					];

					$this->connection->setTimeout( $queryOptions['timeout'] );
					$pageType = $this->connection->getNamespaceType( $this->indexBaseName );
					$match = new \Elastica\Query\Match();
					$match->setField( 'name', $name );
					$query = new \Elastica\Query( $match );
					$query->setParam( '_source', false );
					$query->addParam( 'stats', 'namespace' );
					$resultSet = $pageType->search( $query, $queryOptions );
					// @todo check for partial results due to timeout?
					return $this->success( $resultSet->getResults() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			});
	}

	/**
	 * @return \Elastica\Search
	 */
	protected  function buildSearch() {

		if ( $this->resultsType === null ) {
			$this->resultsType = new FullTextResultsType( FullTextResultsType::HIGHLIGHT_ALL );
		}

		$query = new \Elastica\Query();
		$query->setParam( '_source', $this->resultsType->getSourceFiltering() );
		$query->setParam( 'fields', $this->resultsType->getFields() );

		$extraIndexes = [];
		$namespaces = $this->searchContext->getNamespaces();

		$this->overrideConnectionIfNeeded();
		if ( $namespaces ) {
			$extraIndexes = $this->getAndFilterExtraIndexes();
			$this->searchContext->addFilter( new \Elastica\Query\Terms( 'namespace', $namespaces ) );
		}

		$this->installBoosts();
		$query->setQuery( $this->searchContext->getQuery() );

		$highlight = $this->searchContext->getHighlight( $this->resultsType );
		if ( $highlight ) {
			$query->setHighlight( $highlight );
		}

		if ( $this->searchContext->getSuggest() ) {
			if ( interface_exists( 'Elastica\\ArrayableInterface' ) ) {
				// Elastica 2.3.x.  For some reason it unwraps our suggest
				// query when we don't want it to, so wrap it one more time
				// to make the unwrap do nothing.
				$query->setParam( 'suggest', [
					'suggest' => $this->searchContext->getSuggest()
				] );
			} else {
				$query->setParam( 'suggest', $this->searchContext->getSuggest() );
			}
			$query->addParam( 'stats', 'suggest' );
		}
		if( $this->offset ) {
			$query->setFrom( $this->offset );
		}
		if( $this->limit ) {
			$query->setSize( $this->limit );
		}

		if ( $this->sort != 'relevance' ) {
			// Clear rescores if we aren't using relevance as the search sort because they aren't used.
			$this->searchContext->clearRescore();
		} elseif ( $this->searchContext->hasRescore() ) {
			$query->setParam( 'rescore', $this->searchContext->getRescore() );
		}

		$query->addParam( 'stats', $this->searchContext->getSearchType() );
		switch ( $this->sort ) {
		case 'relevance':
			break;  // The default
		case 'title_asc':
			$query->setSort( [ 'title.keyword' => 'asc' ] );
			break;
		case 'title_desc':
			$query->setSort( [ 'title.keyword' => 'desc' ] );
			break;
		case 'incoming_links_asc':
			$query->setSort( [ 'incoming_links' => [
				'order' => 'asc',
				'missing' => '_first',
			] ] );
			break;
		case 'incoming_links_desc':
			$query->setSort( [ 'incoming_links' => [
				'order' => 'desc',
				'missing' => '_last',
			] ] );
			break;
		default:
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Invalid sort type: {sort}",
				[ 'sort' => $this->sort ]
			);
		}

		// Setup the search
		$queryOptions = [
			\Elastica\Search::OPTION_TIMEOUT => $this->getTimeout(),
		];
		// @todo when switching to multi-search this has to be provided at the top level
		if ( $this->config->get( 'CirrusSearchMoreAccurateScoringMode' ) ) {
			$queryOptions[\Elastica\Search::OPTION_SEARCH_TYPE] = \Elastica\Search::OPTION_SEARCH_TYPE_DFS_QUERY_THEN_FETCH;
		}

		$indexType = $this->connection->pickIndexTypeForNamespaces( $namespaces );
		$pageType = $this->connection->getPageType( $this->indexBaseName, $indexType );

		$search = $pageType->createSearch( $query, $queryOptions );
		foreach ( $extraIndexes as $i ) {
			$search->addIndex( $i );
		}

		if ( $this->returnExplain ) {
			$query->setExplain( true );
		}

		return $search;
	}

	protected function searchOne() {
		$search = $this->buildSearch();

		if ( !$this->searchContext->areResultsPossible() ) {
			return Status::newGood( new SearchResultSet( true ) );
		}

		$result = $this->searchMulti( [$search] );
		if ( $result->isOK() ) {
			// Convert array of responses to single value
			$value = $result->getValue();
			$result->setResult( true, reset( $value ) );
		}

		return $result;
	}

	/**
	 * Powers full-text-like searches including prefix search.
	 *
	 * @param \Elastica\Search[] $searches
	 * @param ResultsType[] $resultsTypes Specific ResultType instances to use with $searches. Any
	 *  search without a matching key in this array uses $this->resultsType.
	 * @return Status results from the query transformed by the resultsType
	 */
	protected function searchMulti( $searches, array $resultsTypes = [] ) {
		if ( $this->limit <= 0 && ! $this->returnQuery ) {
			if ( $this->returnResult ) {
				return Status::newGood( [
						'description' => 'Canceled due to offset out of bounds',
						'path' => '',
						'result' => [],
				] );
			} else {
				$this->searchContext->setResultsPossible( false );
				$retval = [];
				foreach ( $searches as $key => $search ) {
					$retval[$key] = $this->resultsType->createEmptyResult();
				}
				return Status::newGood( $retval );
			}
		}

		$description = "{queryType} search for '{query}'";
		$log = new MultiSearchRequestLog(
			$this->connection->getClient(),
			"{queryType} search for '{query}'",
			$this->searchContext->getSearchType(),
			[
				'query' => $this->searchContext->getOriginalSearchTerm(),
				'limit' => $this->limit ?: null,
				// null means not requested, '' means not found. If found
				// parent::buildLogContext will replace the '' with an
				// actual suggestion.
				'suggestion' => $this->searchContext->getSuggest() ? '' : null,
			]
		);

		if ( $this->returnQuery ) {
			$retval = [];
			$description = $log->formatDescription();
			foreach ( $searches as $key => $search ) {
				$retval[$key] = [
					'description' => $description,
					'path' => $search->getPath(),
					'params' => $search->getOptions(),
					'query' => $search->getQuery()->toArray(),
					'options' => $search->getOptions(),
				];
			}
			return Status::newGood( $retval );
		}

		// Similar to indexing support only the bulk code path, rather than
		// single and bulk. The extra overhead should be minimal, and the
		// reduced complexity is welcomed.
		$search = new \Elastica\Multi\Search( $this->connection->getClient() );
		$search->addSearches( $searches );

		$this->connection->setTimeout( $this->getTimeout() );

		if ( $this->config->get( 'CirrusSearchMoreAccurateScoringMode' ) ) {
			$search->setSearchType( \Elastica\Search::OPTION_SEARCH_TYPE_DFS_QUERY_THEN_FETCH );
		}

		// Perform the search
		$work = function () use ( $search, $log ) {
			return Util::doPoolCounterWork(
				$this->getPoolCounterType(),
				$this->user,
				function () use ( $search, $log ) {
					try {
						$this->start( $log );
						// @todo only reports the first error, also turns
						// a partial (single search) error into a complete
						// failure across the board. Should be addressed
						// at some point.
						$multiResultSet = $search->search();
						if ( $multiResultSet->hasError() ) {
							return $this->multiFailure( $multiResultSet );
						} else {
							return $this->success( $multiResultSet );
						}
					} catch ( \Elastica\Exception\ExceptionInterface $e ) {
						return $this->failure( $e );
					}
				},
				$this->searchContext->getSearchType() === 'regex'
					? 'cirrussearch-regex-too-busy-error' : null
			);
		};

		// Wrap with caching if needed, but don't cache debugging queries
		$skipCache = $this->returnResult || $this->returnExplain;
		if ( $this->searchContext->getCacheTtl() > 0 && !$skipCache ) {
			$work = function () use ( $work, $searches, $log, $resultsTypes ) {
				$requestStats = MediaWikiServices::getInstance()->getStatsdDataFactory();
				$cache = ObjectCache::getLocalClusterInstance();
				$keyParts = [];
				foreach ( $searches as $key => $search ) {
					$resultsType = isset( $resultsTypes[$key] ) ? $resultsTypes[$key] : $this->resultsType;
					$keyParts[] = $search->getPath() .
						serialize( $search->getOptions() ) .
						serialize( $search->getQuery()->toArray() ) .
						serialize( $resultsType );
				}
				$key = $cache->makeKey( 'cirrussearch', 'search', 'v2', md5(
					implode( '|', $keyParts )
				) );
				$cacheResult = $cache->get( $key );
				$statsKey = $this->getQueryCacheStatsKey();
				if ( $cacheResult ) {
					list( $logVariables, $multiResultSet ) = $cacheResult;
					$requestStats->increment("$statsKey.hit");
					$log->setCachedResult( $logVariables );
					$this->successViaCache( $log );
					return $multiResultSet;
				} else {
					$requestStats->increment("$statsKey.miss");
				}

				$multiResultSet = $work();

				if ( $multiResultSet->isOK() ) {
					$isPartialResult = false;
					foreach ( $multiResultSet->getValue()->getResultSets() as $resultSet ) {
						$responseData = $resultSet->getResponse()->getData();
						if ( isset( $responseData['timed_out'] ) && $responseData['timed_out'] ) {
							$isPartialResult = true;
							break;
						}
					}
					if ( !$isPartialResult ) {
						$requestStats->increment("$statsKey.set");
						$cache->set(
							$key,
							[$log->getLogVariables(), $multiResultSet],
							$this->searchContext->getCacheTtl()
						);
					}
				}

				return $multiResultSet;
			};
		}

		$status = $work();

		// @todo Does this need anything special for multi-search changes?
		if ( !$status->isOK() ) {
			return $status;
		}

		$retval = [];
		if ( $this->returnResult ) {
			$description = $log->formatDescription();
			foreach ( $status->getValue()->getResultSets() as $key => $resultSet ) {
				$retval[$key] = [
					'description' => $description,
					'path' => $searches[$key]->getPath(),
					'result' => $resultSet->getResponse()->getData(),
				];
			}
			return Status::newGood( $retval );
		}

		$timedOut = false;
		foreach ( $status->getValue()->getResultSets() as $key => $resultSet ) {
			$response = $resultSet->getResponse();
			if ( $response->hasError() ) {
				// @todo error handling
				$retval[$key] = null;
			} else {
				$responseData = $response->getData();

				$resultsType = isset( $resultsTypes[$key] ) ? $resultsTypes[$key] : $this->resultsType;
				$retval[$key] = $resultsType->transformElasticsearchResult(
					$this->searchContext,
					$resultSet
				);
				if ( $resultSet->hasTimedOut() ) {
					$timedOut = true;
				}
			}
		}

		if ( $timedOut ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				$log->getDescription() . " timed out and only returned partial results!",
				$log->getLogVariables()
			);
			$status->warning( 'cirrussearch-timed-out' );
		}

		$status->setResult( true, $retval );

		return $status;
	}

	/**
	 * Retrieve the extra indexes for our searchable namespaces, if any
	 * exist. If they do exist, also add our wiki to our notFilters so
	 * we can filter out duplicates properly.
	 *
	 * @return string[]
	 */
	protected function getAndFilterExtraIndexes() {
		if ( $this->searchContext->getLimitSearchToLocalWiki() ) {
			return [];
		}
		$extraIndexes = OtherIndexes::getExtraIndexesForNamespaces(
			$this->searchContext->getNamespaces()
		);
		if ( $extraIndexes ) {
			$this->searchContext->addNotFilter( new \Elastica\Query\Term(
				[ 'local_sites_with_dupe' => $this->indexBaseName ]
			) );
		}
		return $extraIndexes;
	}

	/**
	 * If there is any boosting to be done munge the the current query to get it right.
	 */
	private function installBoosts() {
		if ( $this->sort !== 'relevance' ) {
			// Boosts are irrelevant if you aren't sorting by, well, relevance
			return;
		}

		$builder = new RescoreBuilder( $this->searchContext );
		$this->searchContext->mergeRescore( $builder->build() );
	}

	/**
	 * @param string $term
	 * @throws UsageException
	 */
	private function checkTitleSearchRequestLength( $term ) {
		$requestLength = mb_strlen( $term );
		if ( $requestLength > self::MAX_TITLE_SEARCH ) {
			throw new UsageException( 'Prefix search request was longer than the maximum allowed length.' .
				" ($requestLength > " . self::MAX_TITLE_SEARCH . ')', 'request_too_long', 400 );
		}
	}

	/**
	 * @param string $term
	 * @return Status
	 */
	private function checkTextSearchRequestLength( $term ) {
		$requestLength = mb_strlen( $term );
		if (
			$requestLength > self::MAX_TEXT_SEARCH &&
			// allow category intersections longer than the maximum
			strpos( $term, 'incategory:' ) === false
		) {
			return Status::newFatal( 'cirrussearch-query-too-long', $this->language->formatNum( $requestLength ), $this->language->formatNum( self::MAX_TEXT_SEARCH ) );
		}
		return Status::newGood();
	}

	/**
	 * Attempt to suck a leading namespace followed by a colon from the query string.  Reaches out to Elasticsearch to
	 * perform normalized lookup against the namespaces.  Should be fast but for the network hop.
	 *
	 * @param string &$query
	 */
	public function updateNamespacesFromQuery( &$query ) {
		$colon = strpos( $query, ':' );
		if ( $colon === false ) {
			return;
		}
		$namespaceName = substr( $query, 0, $colon );
		$status = $this->findNamespace( $namespaceName );
		// Failure case is already logged so just handle success case
		if ( !$status->isOK() ) {
			return;
		}
		$foundNamespace = $status->getValue();
		if ( !$foundNamespace ) {
			return;
		}
		$foundNamespace = $foundNamespace[ 0 ];
		$query = substr( $query, $colon + 1 );
		$this->searchContext->setNamespaces( [ $foundNamespace->getId() ] );
	}

	/**
	 * @return SearchContext
	 */
	public function getSearchContext() {
		return $this->searchContext;
	}

	private function getPoolCounterType() {
		$poolCounterTypes = array(
			'regex' => 'CirrusSearch-Regex',
			'prefix' => 'CirrusSearch-Prefix',
		);
		if ( isset( $poolCounterTypes[$this->searchContext->getSearchType()] ) ) {
			return $poolCounterTypes[$this->searchContext->getSearchType()];
		}
		return 'CirrusSearch-Search';
	}

	private function getTimeout() {
		if ( $this->searchContext->getSearchType() === 'regex' ) {
			$type = 'regex';
		} else {
			$type = 'default';
		}

		return $this->config->getElement( 'CirrusSearchSearchShardTimeout', $type );
	}

	/**
	 * Some queries, like more like this, are quite expensive and can cause
	 * latency spikes. This allows redirecting queries using particular
	 * features to specific clusters.
	 */
	private function overrideConnectionIfNeeded() {
		$overrides = $this->config->get( 'CirrusSearchClusterOverrides' );
		foreach ( $overrides as $feature => $cluster ) {
			if ( $this->searchContext->isSyntaxUsed( $feature ) ) {
				$this->connection = Connection::getPool( $this->config, $cluster );
				return;
			}
		}
	}

	/**
	 * @return string The stats key used for reporting hit/miss rates of the
	 *  application side query cache.
	 */
	protected function getQueryCacheStatsKey() {
		$type = $this->searchContext->getSearchType();
		return "CirrusSearch.query_cache.$type";
	}

	/**
	 * @param string $description
	 * @param string $queryType
	 * @param string[] $extra
	 * @return SearchRequestLog
	 */
	protected function newLog( $description, $queryType, array $extra = [] ) {
		return new SearchRequestLog(
			$this->connection->getClient(),
			$description,
			$queryType,
			$extra
		);
	}
}
