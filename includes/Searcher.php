<?php

namespace CirrusSearch;

use CirrusSearch\Parser\FullTextKeywordRegistry;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Query\CountContentWordsBuilder;
use CirrusSearch\Query\NearMatchQueryBuilder;
use CirrusSearch\Query\PrefixSearchQueryBuilder;
use CirrusSearch\Search\SearchRequestBuilder;
use CirrusSearch\Search\TitleResultsType;
use CirrusSearch\Search\ResultsType;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\TeamDraftInterleaver;
use CirrusSearch\Query\FullTextQueryBuilder;
use Elastica\Exception\RuntimeException;
use Elastica\Multi\Search as MultiSearch;
use Elastica\Query\BoolQuery;
use Elastica\Query\MultiMatch;
use Elastica\Search;
use Language;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use ObjectCache;
use RequestContext;
use SearchResultSet;
use Status;
use User;
use WebRequest;

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
	const HIGHLIGHT_PRE_MARKER = ''; // \uE000. Can't be a unicode literal until php7
	const HIGHLIGHT_PRE = '<span class="searchmatch">';
	const HIGHLIGHT_POST_MARKER = ''; // \uE001
	const HIGHLIGHT_POST = '</span>';

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
	protected $offset;

	/**
	 * @var integer maximum number of result
	 */
	protected $limit;

	/**
	 * @var Language language of the wiki
	 */
	private $language;

	/**
	 * @var ResultsType|null type of results.  null defaults to FullTextResultsType
	 * @deprecated
	 */
	protected $resultsType;

	/**
	 * @var string sort type
	 */
	private $sort = 'relevance';

	/**
	 * @var string index base name to use
	 */
	protected $indexBaseName;

	/**
	 * @var boolean just return the array that makes up the query instead of searching
	 */
	protected $returnQuery = false;

	/**
	 * @var boolean return raw Elasticsearch result instead of processing it
	 */
	protected $returnResult = false;

	/**
	 * @var string|null return explanation with results
	 */
	protected $returnExplain;

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
	 * @var string|\Elastica\Type
	 */
	private $pageType;

	/**
	 * @param Connection $conn
	 * @param int $offset Offset the results by this much
	 * @param int $limit Limit the results to this many
	 * @param SearchConfig|null $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|bool $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 */
	public function __construct( Connection $conn, $offset, $limit, SearchConfig $config, array $namespaces = null,
		User $user = null, $index = false
	) {
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
		$this->searchContext = new SearchContext( $this->config, $namespaces );
	}

	/**
	 * @param ResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->searchContext->setResultsType( $resultsType );
		// BC, deprecated
		/** @suppress PhanDeprecatedProperty BC */
		$this->resultsType = $resultsType;
	}

	/**
	 * @param bool $returnQuery just return the array that makes up the query instead of searching
	 */
	public function setReturnQuery( $returnQuery ) {
		$this->returnQuery = $returnQuery;
	}

	/**
	 * @param bool $dumpResult return raw Elasticsearch result instead of processing it
	 */
	public function setDumpResult( $dumpResult ) {
		$this->returnResult = $dumpResult;
	}

	/**
	 * @param string|null $returnExplain return query explanation
	 */
	public function setReturnExplain( $returnExplain ) {
		$this->returnExplain = $returnExplain;
	}

	/**
	 * Is this searcher used to return debugging info?
	 * @return bool true if the search will return raw output
	 */
	public function isReturnRaw() {
		return $this->returnResult || $this->returnQuery;
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
	 * @param bool $showSuggestion should this search suggest alternative searches that might be better?
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

		$builderSettings = $this->config->getProfileService()
			->loadProfileByName( SearchProfileService::FT_QUERY_BUILDER, $this->searchContext->getFulltextQueryBuilderProfile() );
		$features = ( new FullTextKeywordRegistry( $this->config ) )->getKeywords();
		/** @var FullTextQueryBuilder $qb */
		$qb = new $builderSettings['builder_class'](
			$this->config,
			$features,
			$builderSettings['settings']
		);

		if ( !( $qb instanceof FullTextQueryBuilder ) ) {
			throw new RuntimeException( "Bad builder class configured: {$builderSettings['builder_class']}" );
		}

		\Hooks::run( 'CirrusSearchFulltextQueryBuilder', [ &$qb, $this->searchContext ] );
		// Query builder could be replaced here!
		if ( !( $qb instanceof FullTextQueryBuilder ) ) {
			throw new RuntimeException( "Bad query builder object override, must implement FullTextQueryBuilder!" );
		}

		$showSuggestion = $showSuggestion && $this->offset == 0
			&& $this->config->get( 'CirrusSearchEnablePhraseSuggest' );
		$qb->build( $this->searchContext, $term, $showSuggestion );

		return $qb;
	}

	/**
	 * Search articles with provided term.
	 * @param string $term term to search
	 * @param bool $showSuggestion should this search suggest alternative searches that might be better?
	 * @return Status
	 */
	public function searchText( $term, $showSuggestion ) {
		$checkLengthStatus = $this->checkTextSearchRequestLength( $term );
		$this->searchContext->setOriginalSearchTerm( $term );
		if ( !$checkLengthStatus->isOK() ) {
			return $checkLengthStatus;
		}

		// Searcher needs to be cloned before any actual query building is done.
		$interleaveSearcher = $this->buildInterleaveSearcher();

		$searches = [];
		$qb = $this->buildFullTextSearch( $term, $showSuggestion );
		$searches[] = $this->buildSearch();

		if ( !$this->searchContext->areResultsPossible() ) {
			return Status::newGood( new SearchResultSet( true ) );
		}

		if ( $interleaveSearcher !== null ) {
			$interleaveSearcher->buildFullTextSearch( $term, $showSuggestion );
			$interleaveSearch = $interleaveSearcher->buildSearch();
			if ( $this->areSearchesTheSame( $searches[0], $interleaveSearch ) ) {
				$interleaveSearcher = null;
			} else {
				$searches[] = $interleaveSearch;
			}
		}

		$status = $this->searchMulti( $searches );
		if ( !$status->isOK() ) {
			if ( ElasticaErrorHandler::isParseError( $status ) ) {
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

		if ( $interleaveSearcher === null ) {
			// Convert array of responses to single value
			$value = $status->getValue();
			$response = reset( $value );
		} else {
			// Evil hax to support cirrusDumpResult. This is probably
			// very fragile.
			$value = $status->getValue();
			if ( $value[0] instanceof ResultSet ) {
				$interleaver = new TeamDraftInterleaver( $this->searchContext->getOriginalSearchTerm() );
				$response = $interleaver->interleave( $value[0], $value[1], $this->limit );
			} else {
				$response = $value;
			}
		}
		$status->setResult( true, $response );

		foreach ( $this->searchContext->getWarnings() as $warning ) {
			call_user_func_array( [ $status, 'warning' ], $warning );
		}

		return $status;
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
		$connection = $this->getOverriddenConnection();
		$indexType = $connection->pickIndexTypeForNamespaces(
			$this->searchContext->getNamespaces()
		);

		// The worst case would be to have all ids duplicated in all available indices.
		// We set the limit accordingly
		$size = count( $connection->getAllIndexSuffixesForNamespaces(
			$this->searchContext->getNamespaces()
		) );
		$size *= count( $docIds );

		return Util::doPoolCounterWork(
			$this->getPoolCounterType(),
			$this->user,
			function () use ( $docIds, $sourceFiltering, $indexType, $size, $connection ) {
				try {
					$this->startNewLog( 'get of {indexType}.{docIds}', 'get', [
						'indexType' => $indexType,
						'docIds' => $docIds,
					] );
					// Shard timeout not supported on get requests so we just use the client side timeout
					$connection->setTimeout( $this->getClientTimeout() );
					// We use a search query instead of _get/_mget, these methods are
					// theorically well suited for this kind of job but they are not
					// supported on aliases with multiple indices (content/general)
					$pageType = $connection->getPageType( $this->indexBaseName, $indexType );
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
			} );
	}

	/**
	 * @param string $name
	 * @return Status
	 */
	public function findNamespace( $name ) {
		return Util::doPoolCounterWork(
			'CirrusSearch-NamespaceLookup',
			$this->user,
			function () use ( $name ) {
				try {
					$this->startNewLog( 'lookup namespace for {namespaceName}', 'namespace', [
						'namespaceName' => $name,
						'query' => $name,
					] );
					$queryOptions = [
						'search_type' => 'query_then_fetch',
						'timeout' => $this->getTimeout(),
					];
					$connection = $this->getOverriddenConnection();
					$connection->setTimeout( $this->getClientTimeout() );
					$pageType = $connection->getNamespaceType( $this->indexBaseName );
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
			} );
	}

	/**
	 * @return \Elastica\Search
	 */
	protected function buildSearch() {
		$builder = new SearchRequestBuilder( $this->searchContext, $this->getOverriddenConnection(), $this->indexBaseName );
		return $builder->setLimit( $this->limit )
			->setOffset( $this->offset )
			->setPageType( $this->pageType )
			->setReturnExplain( !is_null( $this->returnExplain ) )
			->setSort( $this->sort )
			->setTimeout( $this->getTimeout() )
			->build();
	}

	/**
	 * Perform a single-query search.
	 * @return Status
	 */
	protected function searchOne() {
		$search = $this->buildSearch();

		if ( !$this->searchContext->areResultsPossible() ) {
			return Status::newGood( new SearchResultSet( true ) );
		}

		$result = $this->searchMulti( [ $search ] );
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
	 *  search without a matching key in this array uses context result type.
	 * @return Status results from the query transformed by the resultsType
	 */
	protected function searchMulti( $searches, array $resultsTypes = [] ) {
		$contextResultsType = $this->searchContext->getResultsType();
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
					$retval[$key] = $contextResultsType->createEmptyResult();
				}
				return Status::newGood( $retval );
			}
		}

		$connection = $this->getOverriddenConnection();
		$log = new MultiSearchRequestLog(
			$connection->getClient(),
			"{queryType} search for '{query}'",
			$this->searchContext->getSearchType(),
			[
				'query' => $this->searchContext->getOriginalSearchTerm(),
				'limit' => $this->limit ?: null,
				// null means not requested, '' means not found. If found
				// parent::buildLogContext will replace the '' with an
				// actual suggestion.
				'suggestion' => $this->searchContext->getSuggest() ? '' : null,
				// Used syntax
				'syntax' => $this->searchContext->getSyntaxUsed(),
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
		$search = new MultiSearch( $connection->getClient() );
		$search->addSearches( $searches );

		$connection->setTimeout( $this->getClientTimeout() );

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
						if ( $multiResultSet->hasError() ||
							// Catches HTTP errors (ex: 5xx) not reported
							// by hasError()
							!$multiResultSet->getResponse()->isOk()
						) {
							return $this->multiFailure( $multiResultSet );
						} else {
							return $this->success( $multiResultSet );
						}
					} catch ( \Elastica\Exception\ExceptionInterface $e ) {
						return $this->failure( $e );
					}
				},
				$this->searchContext->isSyntaxUsed( 'regex' ) ?
					'cirrussearch-regex-too-busy-error' : null
			);
		};

		// Wrap with caching if needed, but don't cache debugging queries
		$skipCache = $this->returnResult || $this->returnExplain;
		if ( $this->searchContext->getCacheTtl() > 0 && !$skipCache ) {
			$work = function () use ( $work, $searches, $log, $resultsTypes, $contextResultsType ) {
				$requestStats = MediaWikiServices::getInstance()->getStatsdDataFactory();
				$cache = ObjectCache::getLocalClusterInstance();
				$keyParts = [];
				foreach ( $searches as $key => $search ) {
					$resultsType = isset( $resultsTypes[$key] ) ? $resultsTypes[$key] : $contextResultsType;
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
					$requestStats->increment( "$statsKey.hit" );
					$log->setCachedResult( $logVariables );
					$this->successViaCache( $log );
					return $multiResultSet;
				} else {
					$requestStats->increment( "$statsKey.miss" );
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
						$requestStats->increment( "$statsKey.set" );
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
				$resultsType = isset( $resultsTypes[$key] ) ? $resultsTypes[$key] : $contextResultsType;
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
			$status->warning( $this->searchContext->isSyntaxUsed( 'regex' )
				? 'cirrussearch-regex-timed-out'
				: 'cirrussearch-timed-out'
			);
		}

		$status->setResult( true, $retval );

		return $status;
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
		$poolCounterTypes = [
			'regex' => 'CirrusSearch-Regex',
			'prefix' => 'CirrusSearch-Prefix',
			'more_like' => 'CirrusSearch-MoreLike',
		];
		foreach ( $poolCounterTypes as $type => $counter ) {
			if ( $this->searchContext->isSyntaxUsed( $type ) ) {
				return $counter;
			}
		}
		return 'CirrusSearch-Search';
	}

	/**
	 * @return string search retrieval timeout
	 */
	private function getTimeout() {
		if ( $this->searchContext->isSyntaxUsed( 'regex' ) ) {
			$type = 'regex';
		} else {
			$type = 'default';
		}

		return $this->config->getElement( 'CirrusSearchSearchShardTimeout', $type );
	}

	/**
	 * @return int the client side timeout
	 */
	private function getClientTimeout() {
		if ( $this->searchContext->getSearchType() === 'regex' ) {
			$type = 'regex';
		} else {
			$type = 'default';
		}

		return $this->config->getElement( 'CirrusSearchClientSideSearchTimeout', $type );
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
			$this->getOverriddenConnection()->getClient(),
			$description,
			$queryType,
			$extra
		);
	}

	/**
	 * Set search options from request params
	 * @param WebRequest|null $request
	 */
	public function setOptionsFromRequest( WebRequest $request = null ) {
		if ( !$request ) {
			return;
		}
		$this->returnQuery = $request->getVal( 'cirrusDumpQuery' ) !== null;
		$this->returnResult = $request->getVal( 'cirrusDumpResult' ) !== null;
		$this->returnExplain = $request->getVal( 'cirrusExplain' );
	}

	/**
	 * If we're supposed to create raw result, create and return it,
	 * or output it and finish.
	 * @param mixed $result Search result data
	 * @param WebRequest $request Request context
	 * @param bool $dumpAndDie Whether we should dump result to output or just return it.
	 * @return string The new raw result.
	 */
	public function processRawReturn( $result, WebRequest $request, $dumpAndDie = true ) {
		$header = null;

		if ( in_array( $this->returnExplain, [ 'verbose', 'pretty', 'hot' ] ) ) {
			$header = 'Content-type: text/html; charset=UTF-8';
			$printer = new ExplainPrinter( $this->returnExplain );
			$result = $printer->format( $result );
		} else {
			$header = 'Content-type: application/json; charset=UTF-8';
			if ( $result === null ) {
				$result = '{}';
			} else {
				$result = json_encode( $result, JSON_PRETTY_PRINT );
			}
		}

		if ( $dumpAndDie ) {
			// When dumping the query we skip _everything_ but echoing the query.
			RequestContext::getMain()->getOutput()->disable();
			$request->response()->header( $header );
			echo $result;
			exit();
		}

		return $result;
	}

	/**
	 * Search titles in archive
	 * @param string $term
	 * @return Status<Title[]>
	 */
	public function searchArchive( $term ) {
		list( $term, ) = $this->searchContext->escaper()->fixupWholeQueryString( $term );
		$this->setResultsType( new TitleResultsType() );

		$this->pageType = $this->getOverriddenConnection()->getArchiveType( $this->indexBaseName );

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

		$fuzzy = new \Elastica\Query\Match();
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
		$fixNow = function ( &$value, $key ) {
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

	private function buildInterleaveSearcher() {
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

		$config = new HashSearchConfig( $overrides, [ 'inherit' ] );
		$other = clone $this;
		$other->config = $config;
		$other->searchContext = $other->searchContext->withConfig( $config );

		return $other;
	}
}
