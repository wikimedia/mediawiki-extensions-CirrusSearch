<?php

namespace CirrusSearch;

use CirrusSearch\Fallbacks\FallbackRunner;
use CirrusSearch\Fallbacks\SearcherFactory;
use CirrusSearch\Maintenance\NullPrinter;
use CirrusSearch\MetaStore\MetaStoreIndex;
use CirrusSearch\Parser\BasicQueryClassifier;
use CirrusSearch\Parser\FullTextKeywordRegistry;
use CirrusSearch\Parser\NamespacePrefixParser;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Query\CountContentWordsBuilder;
use CirrusSearch\Query\FullTextQueryBuilder;
use CirrusSearch\Query\KeywordFeature;
use CirrusSearch\Query\NearMatchQueryBuilder;
use CirrusSearch\Query\PrefixSearchQueryBuilder;
use CirrusSearch\Search\BaseCirrusSearchResultSet;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\MSearchRequests;
use CirrusSearch\Search\MSearchResponses;
use CirrusSearch\Search\ResultsType;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchRequestBuilder;
use CirrusSearch\Search\TeamDraftInterleaver;
use CirrusSearch\Search\TitleHelper;
use CirrusSearch\Search\TitleResultsType;
use Elastica\Exception\RuntimeException;
use Elastica\Multi\Search as MultiSearch;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MultiMatch;
use Elastica\Search;
use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\MWException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\Assert;
use Wikimedia\ObjectFactory\ObjectFactory;
use Wikimedia\Stats\StatsFactory;

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
class Searcher extends ElasticsearchIntermediary implements SearcherFactory {
	public const SUGGESTION_HIGHLIGHT_PRE = '<em>';
	public const SUGGESTION_HIGHLIGHT_POST = '</em>';
	public const HIGHLIGHT_PRE_MARKER = ''; // \uE000. Can't be a unicode literal until php7
	public const HIGHLIGHT_PRE = '<span class="searchmatch">';
	public const HIGHLIGHT_POST_MARKER = ''; // \uE001
	public const HIGHLIGHT_POST = '</span>';

	/**
	 * Maximum offset + limit depth allowed. As in the deepest possible result
	 * to return. Too deep will cause very slow queries. 10,000 feels plenty
	 * deep. This should be <= index.max_result_window in elasticsearch.
	 */
	private const MAX_OFFSET_LIMIT = 10000;

	/**
	 * Identifies the main search in MSearchRequests/MSearchResponses
	 */
	public const MAINSEARCH_MSEARCH_KEY = '__main__';

	/**
	 * Identifies the "tested" search request in MSearchRequests/MSearchResponses
	 */
	private const INTERLEAVED_MSEARCH_KEY = '__interleaved__';

	/**
	 * @var int search offset
	 */
	protected $offset;

	/**
	 * @var int maximum number of result
	 */
	protected $limit;

	/**
	 * @var string sort type
	 */
	private $sort = 'relevance';

	/**
	 * @var string index base name to use
	 */
	protected $indexBaseName;

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
	 * Indexing type we'll be using.
	 * @var string|\Elastica\Index
	 */
	private $index;

	/**
	 * @var NamespacePrefixParser|null
	 */
	private $namespacePrefixParser;
	/**
	 * @var InterwikiResolver
	 */
	protected $interwikiResolver;

	/** @var TitleHelper */
	protected $titleHelper;
	/**
	 * @var CirrusSearchHookRunner
	 */
	protected $cirrusSearchHookRunner;

	/**
	 * @param Connection $conn
	 * @param int $offset Offset the results by this much
	 * @param int $limit Limit the results to this many
	 * @param SearchConfig $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|bool $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 * @param CirrusDebugOptions|null $options the debugging options to use or null to use defaults
	 * @param NamespacePrefixParser|null $namespacePrefixParser
	 * @param InterwikiResolver|null $interwikiResolver
	 * @param TitleHelper|null $titleHelper
	 * @param CirrusSearchHookRunner|null $cirrusSearchHookRunner
	 * @see CirrusDebugOptions::defaultOptions()
	 */
	public function __construct(
		Connection $conn, $offset,
		$limit,
		SearchConfig $config,
		?array $namespaces = null,
		?User $user = null,
		$index = false,
		?CirrusDebugOptions $options = null,
		?NamespacePrefixParser $namespacePrefixParser = null,
		?InterwikiResolver $interwikiResolver = null,
		?TitleHelper $titleHelper = null,
		?CirrusSearchHookRunner $cirrusSearchHookRunner = null
	) {
		parent::__construct(
			$conn,
			$user,
			$config->get( 'CirrusSearchSlowSearch' ),
			$config->get( 'CirrusSearchExtraBackendLatency' )
		);
		$this->config = $config;
		$this->setOffsetLimit( $offset, $limit );
		$this->indexBaseName = $index ?: $config->get( SearchConfig::INDEX_BASE_NAME );
		// TODO: Make these params mandatory once WBCS stops extending this class
		$this->namespacePrefixParser = $namespacePrefixParser;
		$this->interwikiResolver = $interwikiResolver ?: MediaWikiServices::getInstance()->getService( InterwikiResolver::SERVICE );
		$this->titleHelper = $titleHelper ?: new TitleHelper( WikiMap::getCurrentWikiId(), $this->interwikiResolver );
		$this->cirrusSearchHookRunner = $cirrusSearchHookRunner ?: new CirrusSearchHookRunner(
			MediaWikiServices::getInstance()->getHookContainer() );
		$this->searchContext = new SearchContext( $this->config, $namespaces, $options, null, null, $this->cirrusSearchHookRunner );
	}

	/**
	 * Unified search public entry-point.
	 *
	 * NOTE: only fulltext search supported for now.
	 * @param SearchQuery $query
	 * @return Status
	 */
	public function search( SearchQuery $query ) {
		if ( $query->getDebugOptions()->isCirrusDumpQueryAST() ) {
			return Status::newGood( [ 'ast' => $query->getParsedQuery()->toArray() ] );
		}
		// TODO: properly pass the profile context name and its params once we have a dispatch service.
		$this->searchContext = SearchContext::fromSearchQuery( $query, FallbackRunner::create( $query, $this->interwikiResolver ),
			$this->cirrusSearchHookRunner );
		$this->setOffsetLimit( $query->getOffset(), $query->getLimit() );
		$this->config = $query->getSearchConfig();
		$this->sort = $query->getSort();

		if ( $query->getSearchEngineEntryPoint() === SearchQuery::SEARCH_TEXT ) {
			$this->searchContext->setResultsType(
				new FullTextResultsType(
					$this->searchContext->getFetchPhaseBuilder(),
					$query->getParsedQuery()->isQueryOfClass( BasicQueryClassifier::COMPLEX_QUERY ),
					$this->titleHelper,
					$query->getExtraFieldsToExtract(),
					$this->searchContext->getConfig()->getElement( 'CirrusSearchDeduplicateInMemory' ) === true
				)
			);
			return $this->searchTextInternal( $query->getParsedQuery()->getQueryWithoutNsHeader() );
		} else {
			throw new \RuntimeException( 'Only ' . SearchQuery::SEARCH_TEXT . ' is supported for now' );
		}
	}

	/**
	 * @param ResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->searchContext->setResultsType( $resultsType );
	}

	/**
	 * Is this searcher used to return debugging info?
	 * @return bool true if the search will return raw output
	 */
	public function isReturnRaw() {
		return $this->searchContext->getDebugOptions()->isReturnRaw();
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
	 * @param bool $limitSearchToLocalWiki should the results be limited?
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
		( new NearMatchQueryBuilder() )->build( $this->searchContext, $term );
		return $this->searchOne();
	}

	/**
	 * Perform a sum over the number of words in the content index
	 * @return Status status containing a single integer
	 */
	public function countContentWords() {
		( new CountContentWordsBuilder() )->build( $this->searchContext );
		$this->limit = 1;
		return $this->searchOne();
	}

	/**
	 * Perform a prefix search.
	 * @param string $term text by which to search
	 * @param string[] $variants variants to search for
	 * @return Status status containing results defined by resultsType on success
	 */
	public function prefixSearch( $term, $variants = [] ) {
		( new PrefixSearchQueryBuilder() )->build( $this->searchContext, $term, $variants );
		return $this->searchOne();
	}

	/**
	 * Build full text search for articles with provided term. All the
	 * state is applied to $this->searchContext. The returned query
	 * builder can be used to build a degraded query if necessary.
	 *
	 * @param string $term term to search
	 * @return FullTextQueryBuilder
	 */
	protected function buildFullTextSearch( $term ) {
		// Convert the unicode character 'ideographic whitespace' into standard
		// whitespace. Cirrussearch treats them both as normal whitespace, but
		// the preceding isn't appropriately trimmed.
		// No searching for nothing! That takes forever!
		$term = trim( str_replace( "\xE3\x80\x80", " ", $term ) );
		if ( $term === '' ) {
			$this->searchContext->setResultsPossible( false );
		}

		$builderSettings = $this->config->getProfileService()
			->loadProfileByName( SearchProfileService::FT_QUERY_BUILDER,
				$this->searchContext->getFulltextQueryBuilderProfile() );
		$features = ( new FullTextKeywordRegistry( $this->config ) )->getKeywords();
		$qb = self::buildFullTextBuilder( $builderSettings, $this->config, $features );

		$qb->build( $this->searchContext, $term );

		if ( $this->searchContext->getSearchQuery() !== null ) {
			$degradeOnParseWarnings = [
				// && test, test AND && test
				'cirrussearch-parse-error-unexpected-token',
				// test AND
				'cirrussearch-parse-error-unexpected-end'
			];
			// Quick hack to avoid sending bad queries to the backend
			foreach ( $this->searchContext->getSearchQuery()->getParsedQuery()->getParseWarnings() as $warning ) {
				if ( in_array( $warning->getMessage(), $degradeOnParseWarnings ) ) {
					$qb->buildDegraded( $this->searchContext );
					return $qb;
				}
			}
		}

		return $qb;
	}

	/**
	 * @param string $term
	 * @return Status
	 */
	private function searchTextInternal( $term ) {
		// Searcher needs to be cloned before any actual query building is done.
		$interleaveSearcher = $this->buildInterleaveSearcher();

		$qb = $this->buildFullTextSearch( $term );
		$mainSearch = $this->buildSearch();
		$searches = MSearchRequests::build( self::MAINSEARCH_MSEARCH_KEY, $mainSearch );
		$description = "{$this->searchContext->getSearchType()} search for '{$this->searchContext->getOriginalSearchTerm()}'";

		if ( !$this->searchContext->areResultsPossible() ) {
			if ( $this->searchContext->getDebugOptions()->isCirrusDumpQuery() ) {
				// return the empty array to suggest that no query will be run
				return Status::newGood( [] );
			}
			$status = $this->emptyResultSet();
			if ( $this->searchContext->getDebugOptions()->isCirrusDumpResult() ) {
				return Status::newGood(
					( new MSearchResponses( [ $status->getValue() ], [] ) )->dumpResults( $description )
				);
			}
			return $status;
		}

		if ( $interleaveSearcher !== null ) {
			$interleaveSearcher->buildFullTextSearch( $term );
			$interleaveSearch = $interleaveSearcher->buildSearch();
			if ( $this->areSearchesTheSame( $mainSearch, $interleaveSearch ) ) {
				$interleaveSearcher = null;
			} else {
				$searches->addRequest( self::INTERLEAVED_MSEARCH_KEY, $interleaveSearch );
			}
		}

		$fallbackRunner = $this->searchContext->getFallbackRunner();
		$fallbackRunner->attachSearchRequests( $searches, $this->connection->getClient() );

		if ( $this->searchContext->getDebugOptions()->isCirrusDumpQuery() ) {
			return $searches->dumpQuery( $description );
		}

		$responses = $this->searchMulti( $searches );
		if ( $responses->hasFailure() ) {
			$status = $responses->getFailure();
			if ( ElasticaErrorHandler::isParseError( $status ) ) {
				// Rebuild the search context because we need a fresh fetchPhaseBuilder
				$this->searchContext = $this->searchContext->withConfig( $this->config );
				if ( $qb->buildDegraded( $this->searchContext ) ) {
					// If that doesn't work we're out of luck but it should.
					// There no guarantee it'll work properly with the syntax
					// we've built above but it'll do _something_ and we'll
					// still work on fixing all the parse errors that come in.
					$status = $this->searchOne();
				}
			}
			return $status;
		}

		if ( $this->searchContext->getDebugOptions()->isCirrusDumpResult() ) {
			return $responses->dumpResults( $description );
		}

		$rType = $this->getSearchContext()->getResultsType();
		$mainSet = $responses->transformAsResultSet( $rType, self::MAINSEARCH_MSEARCH_KEY );
		if ( $interleaveSearcher !== null ) {
			$interleaver = new TeamDraftInterleaver( $this->searchContext->getOriginalSearchTerm() );
			$testedSet = $responses->transformAsResultSet( $rType, self::INTERLEAVED_MSEARCH_KEY );
			$response = $interleaver->interleave( $mainSet, $testedSet, $this->limit );
		} else {
			$response = $mainSet;
		}

		$status = Status::newGood();
		if ( $this->namespacePrefixParser !== null ) {
			$status = Status::newGood( $fallbackRunner->run( $this, $response, $responses,
				$this->namespacePrefixParser, $this->cirrusSearchHookRunner ) );
			$this->appendMetrics( $fallbackRunner );
		}

		foreach ( $this->searchContext->getWarnings() as $warning ) {
			$status->warning( ...$warning );
		}
		return $status;
	}

	/**
	 * Get the page with $docId.  Note that the result is a status containing _all_ pages found.
	 * It is possible to find more then one page if the page is in multiple indexes.
	 * @param string[] $docIds array of document ids
	 * @param string[]|bool $sourceFiltering source filtering to apply
	 * @param bool $usePoolCounter false to disable the pool counter
	 * @return Status containing pages found, containing an empty array if not found,
	 *    or an error if there was an error
	 */
	public function get( array $docIds, $sourceFiltering, $usePoolCounter = true ) {
		$connection = $this->getOverriddenConnection();
		$indexSuffix = $connection->pickIndexSuffixForNamespaces(
			$this->searchContext->getNamespaces()
		);

		// The worst case would be to have all ids duplicated in all available indices.
		// We set the limit accordingly
		$size = count( $connection->getAllIndexSuffixesForNamespaces(
			$this->searchContext->getNamespaces()
		) );
		$size *= count( $docIds );

		$work = function () use ( $docIds, $sourceFiltering, $indexSuffix, $size, $connection ) {
			try {
				$this->startNewLog( 'get of {indexSuffix}.{docIds}', 'get', [
					'indexSuffix' => $indexSuffix,
					'docIds' => $docIds,
				] );
				// Shard timeout not supported on get requests so we just use the client side timeout
				$connection->setTimeout( $this->getClientTimeout( 'get' ) );
				// We use a search query instead of _get/_mget, these methods are
				// theorically well suited for this kind of job but they are not
				// supported on aliases with multiple indices (content/general)
				$index = $connection->getIndex( $this->indexBaseName, $indexSuffix );
				$query = new \Elastica\Query( new \Elastica\Query\Ids( $docIds ) );
				if ( is_array( $sourceFiltering ) ) {
					// The title is a required field in the ApiTrait
					if ( !in_array( "title", $sourceFiltering ) ) {
						array_push( $sourceFiltering, "title" );
					}
					$query->setParam( '_source', $sourceFiltering );
				}
				$query->addParam( 'stats', 'get' );
				// We ignore limits provided to the searcher
				// otherwize we could return fewer results than
				// the ids requested.
				$query->setFrom( 0 );
				$query->setSize( $size );
				$resultSet = $index->search( $query, [ 'search_type' => 'query_then_fetch' ] );
				self::throwIfNotOk( $connection, $resultSet->getResponse() );
				return $this->success( $resultSet->getResults(), $connection );
			} catch ( \Elastica\Exception\NotFoundException ) {
				// NotFoundException just means the field didn't exist.
				// It is up to the caller to decide if that is an error.
				return $this->success( [], $connection );
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				return $this->failure( $e, $connection );
			}
		};

		if ( $usePoolCounter ) {
			return Util::doPoolCounterWork( $this->getPoolCounterType(), $this->user, $work );
		} else {
			return $work();
		}
	}

	/**
	 * @param string $name
	 * @return Status
	 */
	private function findNamespace( $name ) {
		return Util::doPoolCounterWork(
			'CirrusSearch-NamespaceLookup',
			$this->user,
			function () use ( $name ) {
				try {
					$this->startNewLog( 'lookup namespace for {namespaceName}', 'namespace', [
						'namespaceName' => $name,
						'query' => $name,
					] );
					$connection = $this->getOverriddenConnection();
					$connection->setTimeout( $this->getClientTimeout( 'namespace' ) );

					// A bit awkward, but accepted as this is the backup
					// implementation of namespace lookup. Deployments should
					// prefer to install php-intl and use utr30.
					$store = ( new MetaStoreIndex( $connection, new NullPrinter(), $this->config ) )
						->namespaceStore();
					$resultSet = $store->find( $name, [
						'timeout' => $this->getTimeout( 'namespace' ),
					] );
					return $this->success( $resultSet->getResults(), $connection );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e, $connection );
				}
			} );
	}

	/**
	 * @return \Elastica\Search
	 */
	protected function buildSearch() {
		$builder = new SearchRequestBuilder(
			$this->searchContext, $this->getOverriddenConnection(), $this->indexBaseName );
		return $builder->setLimit( $this->limit )
			->setOffset( $this->offset )
			->setIndex( $this->index )
			->setSort( $this->sort )
			->setTimeout( $this->getTimeout( $this->searchContext->getSearchType() ) )
			->build();
	}

	/**
	 * Perform a single-query search.
	 * @return Status
	 */
	protected function searchOne() {
		$search = $this->buildSearch();
		$description = "{$this->searchContext->getSearchType()} search for '{$this->searchContext->getOriginalSearchTerm()}'";
		$msearch = MSearchRequests::build( self::MAINSEARCH_MSEARCH_KEY, $search );
		if ( $this->searchContext->getDebugOptions()->isCirrusDumpQuery() ) {
			return $msearch->dumpQuery( $description );
		}
		if ( !$this->searchContext->areResultsPossible() ) {
			return $this->emptyResultSet();
		}

		$mresults = $this->searchMulti( $msearch );

		if ( $mresults->hasFailure() ) {
			return $mresults->getFailure();
		}

		if ( $this->searchContext->getDebugOptions()->isReturnRaw() ) {
			return $mresults->dumpResults( $description );
		}
		return $mresults->transformAndGetSingle( $this->searchContext->getResultsType(), self::MAINSEARCH_MSEARCH_KEY );
	}

	/**
	 * Powers full-text-like searches including prefix search.
	 *
	 * @param MSearchRequests $msearches
	 * @return MSearchResponses search responses
	 */
	protected function searchMulti( MSearchRequests $msearches ) {
		$searches = $msearches->getRequests();
		$contextResultsType = $this->searchContext->getResultsType();
		$cirrusDebugOptions = $this->searchContext->getDebugOptions();
		Assert::precondition( !$cirrusDebugOptions->isCirrusDumpQuery(), 'Must not reach this method when dumping the query' );

		// TODO: should this be moved upper in the stack?
		if ( $this->limit <= 0 ) {
			return $msearches->failure( Status::newFatal( 'cirrussearch-offset-too-large',
				self::MAX_OFFSET_LIMIT, $this->offset ) );
		}

		$connection = $this->getOverriddenConnection();
		$log = new MultiSearchRequestLog(
			$connection->getClient(),
			"{queryType} search for '{query}'",
			$this->searchContext->getSearchType(),
			[
				'query' => $this->searchContext->getOriginalSearchTerm(),
				'limit' => $this->limit ?: null,
				// Used syntax
				'syntax' => $this->searchContext->getSyntaxUsed(),
			],
			$this->searchContext->getNamespaces() ?: []
		);

		// Similar to indexing support only the bulk code path, rather than
		// single and bulk. The extra overhead should be minimal, and the
		// reduced complexity is welcomed.
		$search = new MultiSearch( $connection->getClient() );
		$search->addSearches( $searches );

		$connection->setTimeout( $this->getClientTimeout( $this->searchContext->getSearchType() ) );

		if ( $this->config->get( 'CirrusSearchMoreAccurateScoringMode' ) ) {
			$search->setSearchType( \Elastica\Search::OPTION_SEARCH_TYPE_DFS_QUERY_THEN_FETCH );
		}

		// Perform the search
		$work = function () use ( $search, $log, $connection ) {
			return Util::doPoolCounterWork(
				$this->getPoolCounterType(),
				$this->user,
				function () use ( $search, $log, $connection ) {
					// @todo only reports the first error, also turns
					// a partial (single search) error into a complete
					// failure across the board. Should be addressed
					// at some point.
					return $this->runMSearch( $search, $log, $connection );
				},
				$this->searchContext->isSyntaxUsed( 'regex' ) ?
					'cirrussearch-regex-too-busy-error' : null
			);
		};

		// Wrap with caching if needed, but don't cache debugging queries
		$skipCache = $cirrusDebugOptions->mustNeverBeCached();
		if ( $this->searchContext->getCacheTtl() > 0 && !$skipCache ) {
			$work = function () use ( $work, $searches, $log, $contextResultsType ) {
				$services = MediaWikiServices::getInstance();
				$requestStats = Util::getStatsFactory();
				$cache = $services->getMainWANObjectCache();
				$keyParts = [];
				foreach ( $searches as $key => $search ) {
					$keyParts[] = $search->getPath() .
						serialize( $search->getOptions() ) .
						serialize( $search->getQuery()->toArray() ) .
						( $contextResultsType !== null ? get_class( $contextResultsType ) : "NONE" );
				}
				$key = $cache->makeKey( 'cirrussearch', 'search', 'v2', md5(
					implode( '|', $keyParts )
				) );
				$cacheResult = $cache->get( $key );
				if ( $cacheResult ) {
					[ $logVariables, $multiResultSet ] = $cacheResult;
					$this->recordQueryCacheMetrics( $requestStats, "hit" );
					$log->setCachedResult( $logVariables );
					$this->successViaCache( $log );

					if ( $multiResultSet->isOK() ) {
						/** @var \Elastica\Multi\ResultSet $cachedMResultSet */
						$cachedMResultSet = $multiResultSet->getValue();
						if ( count( $cachedMResultSet->getResultSets() ) !== count( $searches ) ) {
							LoggerFactory::getInstance( 'CirrusSearch' )
								->warning( 'Ignoring a cached Multi/ResultSet wanted {nb_queries} response(s) but received {nb_responses}',
									[
										'nb_queries' => count( $searches ),
										'nb_responses' => count( $cachedMResultSet->getResultSets() )
									] );
							$this->recordQueryCacheMetrics( $requestStats, "incoherent" );
						} else {
							return $multiResultSet;
						}
					} else {
						LoggerFactory::getInstance( 'CirrusSearch' )
							->warning( 'Cached a Status value that is not OK' );
						$this->recordQueryCacheMetrics( $requestStats, "nok" );
					}
				} else {
					$this->recordQueryCacheMetrics( $requestStats, "miss" );
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
						$this->recordQueryCacheMetrics( $requestStats, "set" );
						$cache->set(
							$key,
							[ $log->getLogVariables(), $multiResultSet ],
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
			return $msearches->failure( $status );
		}

		/** @var \Elastica\Multi\ResultSet $response */
		$response = $status->getValue();
		if ( count( $response->getResultSets() ) !== count( $msearches->getRequests() ) ) {
			// Temp hack to investigate T231023 (use php serialize just in case it has some invalid
			// UTF8 sequences that would prevent this message from being sent to logstash
			LoggerFactory::getInstance( 'CirrusSearch' )
				->warning( "Incoherent response received (#searches != #responses) for {query}: {response}",
					[ 'query' => $this->searchContext->getOriginalSearchTerm(), 'response' => serialize( $response->getResponse() ) ] );
			return $msearches->failure( Status::newFatal( 'cirrussearch-backend-error' ) );
		}
		$mreponses = $msearches->toMSearchResponses( $response->getResultSets() );
		if ( $mreponses->hasTimeout() ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				$log->getDescription() . " timed out and only returned partial results!",
				$log->getLogVariables()
			);
			$this->searchContext->addWarning( $this->searchContext->isSyntaxUsed( 'regex' )
				? 'cirrussearch-regex-timed-out'
				: 'cirrussearch-timed-out'
			);
		}
		return $mreponses;
	}

	/**
	 * Attempt to suck a leading namespace followed by a colon from the query string.
	 * Reaches out to Elasticsearch to perform normalized lookup against the namespaces.
	 * Should be fast but for the network hop.
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
		$this->searchContext->setNamespaces( [ $foundNamespace->namespace_id ] );
	}

	/**
	 * @return SearchContext
	 */
	public function getSearchContext() {
		return $this->searchContext;
	}

	private function getPoolCounterType(): string {
		// Default pool counter for all search requests. Note that not all
		// possible requests go through Searcher, so this isn't globally
		// definitive.
		$pool = 'CirrusSearch-Search';
		// Pool counter overrides based on query syntax. Goal is to
		// separate expensive or high-volume traffic into dedicated
		// pools with specific limits. Prefix is only high volume
		// when completion is disabled.
		$poolCounterTypes = [
			'deepcat' => 'CirrusSearch-ExpensiveFullText',
			'regex' => 'CirrusSearch-ExpensiveFullText',
			'prefix' => 'CirrusSearch-Prefix',
			'more_like' => 'CirrusSearch-MoreLike',
		];
		foreach ( $poolCounterTypes as $type => $counter ) {
			if ( $this->searchContext->isSyntaxUsed( $type ) ) {
				$pool = $counter;
				break;
			}
		}
		// Put external automated requests into their own bucket The main idea
		// here is to allow automated access, but prevent that automation from
		// capping out the pools used by interactive queries.
		// It's not clear when the automation bucket should not override other
		// bucketing decisions, for now override everything except Regex since
		// those can be very expensive and usually use a small pool. If both
		// the automation and regex pools filled with regexes it would be
		// significantly more load than expected.
		if ( $pool !== 'CirrusSearch-ExpensiveFullText' && $this->isAutomatedRequest() ) {
			$pool = 'CirrusSearch-Automated';
		}
		return $pool;
	}

	private function isAutomatedRequest(): bool {
		$req = RequestContext::getMain()->getRequest();
		try {
			$ip = $req->getIP();
		} catch ( MWException ) {
			// No IP, typically this means a CLI invocation. We are attempting
			// to segregate external automation, internal automation has its
			// own ability to control configuration and shouldn't be flagged
			if ( MW_ENTRY_POINT === 'cli' ) {
				return false;
			}
			// When can we get here? Is this ever run?
			LoggerFactory::getInstance( 'CirrusSearch' )->info(
				'No IP available during automated request check' );
			return false;
		}
		return Util::looksLikeAutomation(
			$this->config, $ip, $req->getAllHeaders() );
	}

	/**
	 * Some queries, like more like this, are quite expensive and can cause
	 * latency spikes. This allows redirecting queries using particular
	 * features to specific clusters.
	 * @return Connection
	 */
	private function getOverriddenConnection() {
		$overrides = $this->config->get( 'CirrusSearchClusterOverrides' );
		foreach ( $overrides as $feature => $cluster ) {
			if ( $this->searchContext->isSyntaxUsed( $feature ) ) {
				return Connection::getPool( $this->config, $cluster );
			}
		}
		return $this->connection;
	}

	protected function recordQueryCacheMetrics( StatsFactory $requestStats, string $cacheStatus, ?string $type = null ): void {
		$type = $type ?: $this->getSearchContext()->getSearchType();
		$requestStats->getCounter( "query_cache_total" )
			->setLabel( "type", $type )
			->setLabel( "status", $cacheStatus )
			->increment();
	}

	/**
	 * @param string $description
	 * @param string $queryType
	 * @param string[] $extra
	 * @return SearchRequestLog
	 */
	protected function newLog( $description, $queryType, array $extra = [] ) {
		return new SearchRequestLog(
			$this->getOverriddenConnection()->getClient(),
			$description,
			$queryType,
			$extra
		);
	}

	/**
	 * If we're supposed to create raw result, create and return it,
	 * or output it and finish.
	 * @param mixed $result Search result data
	 * @param WebRequest $request Request context
	 * @return string The new raw result.
	 */
	public function processRawReturn( $result, WebRequest $request ) {
		return Util::processSearchRawReturn( $result, $request,
			$this->searchContext->getDebugOptions() );
	}

	/**
	 * Search titles in archive
	 * @param string $term
	 * @return Status<Title[]>
	 */
	public function searchArchive( $term ) {
		$this->searchContext->setOriginalSearchTerm( $term );
		$term = $this->searchContext->escaper()->fixupWholeQueryString( $term );
		$this->setResultsType( new TitleResultsType() );

		// This does not support cross-cluster search, but there is also no use case
		// for cross-wiki archive search.
		$this->index = $this->getOverriddenConnection()->getArchiveIndex( $this->indexBaseName );

		// Setup the search query
		$query = new BoolQuery();

		$multi = new MultiMatch();
		$multi->setType( 'best_fields' );
		$multi->setTieBreaker( 0 );
		$multi->setQuery( $term );
		$multi->setFields( [
			'title.near_match^100',
			'title.near_match_asciifolding^75',
			'title.plain^50',
			'title^25'
		] );
		$multi->setOperator( 'AND' );

		$fuzzy = new \Elastica\Query\MatchQuery();
		$fuzzy->setFieldQuery( 'title.plain', $term );
		$fuzzy->setFieldFuzziness( 'title.plain', 'AUTO' );
		$fuzzy->setFieldOperator( 'title.plain', 'AND' );

		$query->addShould( $multi );
		$query->addShould( $fuzzy );
		$query->setMinimumShouldMatch( 1 );

		$this->sort = 'just_match';

		$this->searchContext->setMainQuery( $query );
		$this->searchContext->addSyntaxUsed( 'archive' );
		$this->searchContext->setRescoreProfile( 'empty' );

		return $this->searchOne();
	}

	/**
	 * Tests if two search objects are equivalent
	 *
	 * @param Search $a
	 * @param Search $b
	 * @return bool
	 */
	private function areSearchesTheSame( Search $a, Search $b ) {
		// same object.
		if ( $a === $b ) {
			return true;
		}

		// Check values not included in toArray()
		if ( $a->getPath() !== $b->getPath()
			|| $a->getOptions() != $b->getOptions()
		) {
			return false;
		}

		$aArray = $a->getQuery()->toArray();
		$bArray = $b->getQuery()->toArray();

		// normalize the 'now' value which contains a timestamp that
		// may vary.
		$fixNow = static function ( &$value, $key ) {
			if ( $key === 'now' && is_int( $value ) ) {
				$value = 12345678;
			}
		};
		array_walk_recursive( $aArray, $fixNow );
		array_walk_recursive( $bArray, $fixNow );

		// Simplest form, requires both arrays to have exact same ordering,
		// types, keys, etc. We could try much harder to remove edge cases,
		// but they probably don't matter too much. The main thing we are
		// looking for is if configuration used for interleaved search didn't
		// have an effect query building. If we get it wrong in some rare
		// cases it should have minimal effects on the interleaved search test.
		return $aArray === $bArray;
	}

	private function buildInterleaveSearcher(): ?self {
		// If we aren't on the first page, or the user has specified
		// some custom magic query options (override rescore profile,
		// etc) then don't interleave.
		if ( $this->offset > 0 || $this->searchContext->isDirty() ) {
			return null;
		}

		// Is interleaving configured?
		$overrides = $this->config->get( 'CirrusSearchInterleaveConfig' );
		if ( $overrides === null ) {
			return null;
		}

		$config = new HashSearchConfig( $overrides, [ HashSearchConfig::FLAG_INHERIT ] );
		$other = clone $this;
		$other->config = $config;
		$other->searchContext = $other->searchContext->withConfig( $config );

		return $other;
	}

	/**
	 * @return Status
	 */
	private function emptyResultSet() {
		$results = $this->searchContext->getResultsType()->createEmptyResult();
		if ( $results instanceof BaseCirrusSearchResultSet ) {
			// TODO: Keywords are very specific to full-text search, while
			// ResultsType and this method are much more general.
			// While awkward, this maintains BC until we decide what to do.
			$results = BaseCirrusSearchResultSet::emptyResultSet(
				$this->searchContext->isSpecialKeywordUsed()
			);
		}
		$status = Status::newGood( $results );
		foreach ( $this->searchContext->getWarnings() as $warning ) {
			$status->warning( ...$warning );
		}
		return $status;
	}

	/**
	 * Apply debug options to the elastica query
	 * @param Query $query
	 * @return Query
	 */
	public function applyDebugOptionsToQuery( Query $query ) {
		return $this->searchContext->getDebugOptions()->applyDebugOptions( $query );
	}

	public function makeSearcher( SearchQuery $query ): self {
		return new self( $this->connection, $query->getOffset(), $query->getLimit(),
			$query->getSearchConfig(), $query->getNamespaces(), $this->user,
			false, $query->getDebugOptions(), $this->namespacePrefixParser, $this->interwikiResolver,
			$this->titleHelper, $this->cirrusSearchHookRunner );
	}

	/**
	 * @param int $offset
	 * @param int $limit
	 */
	private function setOffsetLimit( $offset, $limit ) {
		$this->offset = $offset;
		if ( $offset + $limit > self::MAX_OFFSET_LIMIT ) {
			$this->limit = self::MAX_OFFSET_LIMIT - $offset;
		} else {
			$this->limit = $limit;
		}
	}

	/**
	 * Visible for testing
	 * @return int[] 2 elements array
	 */
	public function getOffsetLimit() {
		Assert::precondition( defined( 'MW_PHPUNIT_TEST' ),
			'getOffsetLimit must only be called for testing purposes' );
		return [ $this->offset, $this->limit ];
	}

	/**
	 * Build a FullTextQueryBuilder defined in the $builderSettings:
	 * format is:
	 * [
	 *     'builder_factory' => callback
	 *     'settings' => ...
	 * ]
	 * where callback must be function that accepts the settings array and returns a FullTextQueryBuilder
	 *
	 * Legacy version:
	 * [
	 *     'builder_class' => ClassName
	 *     'settings' => ...
	 * ]
	 * where ClassName must declare a constructor with these arguments:
	 *   SearchConfig $config, KeywordFeature[] $features, $settings
	 *
	 * Visible for testing only
	 * @param array $builderSettings
	 * @param SearchConfig $config
	 * @param KeywordFeature[] $features
	 * @return FullTextQueryBuilder
	 * @throws \ReflectionException
	 */
	final public static function buildFullTextBuilder(
		array $builderSettings,
		SearchConfig $config,
		array $features
	): FullTextQueryBuilder {
		if ( isset( $builderSettings['builder_class'] ) ) {
			$objectFactorySpecs = [
				'class' => $builderSettings['builder_class'],
				'args' => [
					$config,
					$features,
					$builderSettings['settings']
				]
			];
		} elseif ( $builderSettings['builder_factory'] ) {
			$objectFactorySpecs = [
				'factory' => $builderSettings['builder_factory'],
				'args' => [
					$builderSettings['settings']
				]
			];
		} else {
			throw new \InvalidArgumentException( 'Missing builder_class or builder_factory in the builderSettings' );
		}

		/** @var FullTextQueryBuilder $qb */
		// @phan-suppress-next-line PhanTypeInvalidCallableArraySize
		$qb = ObjectFactory::getObjectFromSpec( $objectFactorySpecs );
		if ( !( $qb instanceof FullTextQueryBuilder ) ) {
			throw new RuntimeException( 'Bad builder class configured.' );
		}

		return $qb;
	}
}
