<?php

use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\InterwikiSearcher;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Searcher;
use CirrusSearch\CompletionSuggester;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\SearchConfig;
use CirrusSearch\Search\CirrusSearchIndexFieldFactory;
use CirrusSearch\Search\FancyTitleResultsType;
use CirrusSearch\Search\TitleResultsType;
use CirrusSearch\UserTesting;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * SearchEngine implementation for CirrusSearch.  Delegates to
 * CirrusSearchSearcher for searches and CirrusSearchUpdater for updates.  Note
 * that lots of search behavior is hooked in CirrusSearchHooks rather than
 * overridden here.
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
class CirrusSearch extends SearchEngine {
	const COMPLETION_SUGGESTER_FEATURE = 'completionSuggester';

	/** @const string name of the prefixsearch fallback profile */
	const COMPLETION_PREFIX_FALLBACK_PROFILE = 'classic';

	/**
	 * @var string The last prefix substituted by replacePrefixes.
	 */
	private $lastNamespacePrefix;

	/**
	 * @var array metrics about the last thing we searched sourced from the
	 *  Searcher instance
	 */
	private $lastSearchMetrics = [];

	/**
	 * @var array additional metrics about the search sourced within this class
	 */
	private $extraSearchMetrics = [];

	/**
	 * @var string
	 */
	private $indexBaseName;

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * Search configuration.
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * Current request.
	 * @var WebRequest
	 */
	private $request;

    /**
     * CirrusSearchIndexFieldFactory
     */
    private $searchIndexFieldFactory;

	/**
	 * Sets the behaviour for the dump query, dump result, etc debugging features.
	 * By default echo's and dies. If this is set to false it will be returned
	 * from Searcher::searchText, which breaks the type signature contracts and should
	 * only be used from unit tests.
	 * @var bool
	 */
	private $dumpAndDie = true;

	public function __construct( $baseName = null ) {
		// Initialize UserTesting before we create a Connection
		// This is useful to do tests accross multiple clusters
		UserTesting::getInstance();
		$this->config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		$this->indexBaseName = $baseName === null
			? $this->config->get( SearchConfig::INDEX_BASE_NAME )
			: $baseName;
		$this->connection = new Connection( $this->config );
		$this->request = RequestContext::getMain()->getRequest();
        $this->searchIndexFieldFactory = new CirrusSearchIndexFieldFactory( $this->config );

		// enable interwiki by default
		$this->features['interwiki'] = true;
	}

	public function setConnection( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * @return Connection
	 */
	public function getConnection() {
		return $this->connection;
	}

	/**
	 * Set search config
	 * @param SearchConfig $config
	 */
	public function setConfig( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * Get search config
	 * @return SearchConfig
	 */
	public function getConfig() {
		return $this->config;
	}

	/**
	 * Override supports to shut off updates to Cirrus via the SearchEngine infrastructure.  Page
	 * updates and additions are chained on the end of the links update job.  Deletes are noticed
	 * via the ArticleDeleteComplete hook.
	 * @param string $feature feature name
	 * @return bool is this feature supported?
	 */
	public function supports( $feature ) {
		switch ( $feature ) {
		case 'search-update':
		case 'list-redirects':
			return false;
		default:
			return parent::supports( $feature );
		}
	}

	/**
	 * Overridden to delegate prefix searching to Searcher.
	 * @param string $term text to search
	 * @return Status Value is either SearchResultSet, or null on error.
	 */
	public function searchText( $term ) {
		$status = $this->searchTextReal( $term, $this->config );
		$matches = $status->getValue();
		if ( !$status->isOK() || !$matches instanceof ResultSet ) {
			return $status;
		}

		if ( $this->isFeatureEnabled( 'rewrite' ) &&
				$matches->isQueryRewriteAllowed( $GLOBALS['wgCirrusSearchInterwikiThreshold'] ) ) {
			$status = $this->searchTextSecondTry( $term, $status );
		}
		ElasticsearchIntermediary::setResultPages( [ $status->getValue() ] );

		return $status;
	}

	/**
	 * Check whether we want to try another language.
	 * @param string $term Search term
	 * @return string|null dbname for another wiki to try, or null
	 */
	private function detectSecondaryLanguage( $term ) {
		if ( !$this->config->isCrossLanguageSearchEnabled() ) {
			return null;
		}

		$detected = null;
		foreach ( $GLOBALS['wgCirrusSearchLanguageDetectors'] as $name => $klass ) {
			if ( !class_exists( $klass ) ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->info(
					"Unknown detector class for {name}: {class}",
					[
						"name" => $name,
						"class" => $klass,
					]
				);
				continue;

			}
			$detector = new $klass();
			if( !( $detector instanceof \CirrusSearch\LanguageDetector\Detector ) ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->info(
					"Bad detector class for {name}: {class}",
					[
						"name" => $name,
						"class" => $klass,
					]
				);
				continue;
			}
			$lang = $detector->detect( $this, $term );
			if ( $lang === $this->config->get( 'wgLanguageCode' ) ) {
				// The query is in the wiki language so we
				// don't need to actually try another wiki.
				// Note that this may not be very accurate for
				// wikis that use deprecated language codes
				// but the interwiki resolver should not return
				// ourselves.
				continue;
			}
			$wiki = MediaWikiServices::getInstance()
				->getService( InterwikiResolver::SERVICE )
				->getSameProjectWikiByLang( $lang );
			if ( !empty( $wiki ) ) {
				// it might be more accurate to attach these to the 'next'
				// log context? It would be inconsistent with the
				// langdetect => false condition which does not have a next
				// request though.
				Searcher::appendLastLogPayload( 'langdetect', $name );
				$detected = $wiki;
				break;
			}
		}
		if ( is_array( $detected  ) ) {
			// Report language detection with search metrics
			// TODO: do we still need this metric? (see T151796)
			$this->extraSearchMetrics['wgCirrusSearchAltLanguage'] = $detected;
			return reset( $detected );
		} else {
			Searcher::appendLastLogPayload( 'langdetect', 'failed' );
			return null;
		}
	}

	/**
	 * @param string $feature
	 * @return bool
	 */
	private function isFeatureEnabled( $feature ) {
		return isset( $this->features[$feature] ) && $this->features[$feature];
	}

	/**
	 * @param string $term
	 * @param Status $oldStatus
	 * @return Status
	 */
	private function searchTextSecondTry( $term, Status $oldStatus ) {
		// TODO: figure out who goes first - language or suggestion?
		$oldResult = $oldStatus->getValue();
		if ( $oldResult->numRows() == 0 && $oldResult->hasSuggestion() ) {
			$rewritten = $oldResult->getSuggestionQuery();
			$rewrittenSnippet = $oldResult->getSuggestionSnippet();
			$this->showSuggestion = false;
			$rewrittenStatus = $this->searchTextReal( $rewritten, $this->config );
			$rewrittenResult = $rewrittenStatus->getValue();
			if (
				$rewrittenResult instanceof ResultSet
				&& $rewrittenResult->numRows() > 0
			) {
				$rewrittenResult->setRewrittenQuery( $rewritten, $rewrittenSnippet );
				if ( $rewrittenResult->numRows() < $GLOBALS['wgCirrusSearchInterwikiThreshold'] ) {
					// replace the result but still try the alt language
					$oldResult = $rewrittenResult;
				} else {
					return $rewrittenStatus;
				}
			}
		}
		$altWikiId = $this->detectSecondaryLanguage( $term );
		if ( $altWikiId ) {
			try {
				$config = $this->config->newInterwikiConfig( $altWikiId );
			} catch ( MWException $e ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->info(
					"Failed to get config for {dbwiki}",
					[
						"dbwiki" => $altWikiId,
						"exception" => $e
					]
				);
				$config = null;
			}
			if ( $config ) {
				$this->indexBaseName = $config->get( SearchConfig::INDEX_BASE_NAME );
				$status = $this->searchTextReal( $term, $config, true );
				$matches = $status->getValue();
				if ( $matches instanceof ResultSet ) {
					$numRows = $matches->numRows();
					$this->extraSearchMetrics['wgCirrusSearchAltLanguageNumResults'] = $numRows;
					// check whether we have second language functionality enabled.
					// This comes after the actual query is run so we can collect metrics about
					// users in the control buckets, and provide them the same latency as users
					// in the test bucket.
					if ( $GLOBALS['wgCirrusSearchEnableAltLanguage'] && $numRows > 0) {
						$oldResult->addInterwikiResults( $matches, SearchResultSet::INLINE_RESULTS, $altWikiId );
					}
				}
			}
		}

		// Don't have any other options yet.
		return $oldStatus;
	}

	/**
	 * Do the hard part of the searching - actual Searcher invocation
	 * @param string $term
	 * @param SearchConfig $config
	 * @param boolean $forceLocal set to true to force searching on the
	 *        local wiki (e.g. avoid searching on commons)
	 * @return Status
	 */
	protected function searchTextReal( $term, SearchConfig $config, $forceLocal = false ) {
		$searcher = new Searcher( $this->connection, $this->offset, $this->limit, $config, $this->namespaces, null, $this->indexBaseName );

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
			$searcher->addSuggestPrefix( '~' );
		}

		$searcher->getSearchContext()->setLimitSearchToLocalWiki( $forceLocal );

		// TODO remove this when we no longer have to support core versions without
		// Ie946150c6796139201221dfa6f7750c210e97166
		if ( method_exists( $this, 'getSort' ) ) {
			$searcher->setSort( $this->getSort() );
		}

		if ( isset( $this->features[SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE] ) ) {
			$profile = $this->features[SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE];
			if ( $this->config->getElement( 'CirrusSearchRescoreProfiles', $profile ) !== null ) {
				$searcher->getSearchContext()->setRescoreProfile( $profile );
			}
		}

		$dumpQuery = $this->request && $this->request->getVal( 'cirrusDumpQuery' ) !== null;
		$searcher->setReturnQuery( $dumpQuery );
		$dumpResult = $this->request && $this->request->getVal( 'cirrusDumpResult' ) !== null;
		$searcher->setDumpResult( $dumpResult );
		$returnExplain = $this->request && $this->request->getVal( 'cirrusExplain' ) !== null;
		$searcher->setReturnExplain( $returnExplain );

		if ( $this->lastNamespacePrefix ) {
			$searcher->addSuggestPrefix( $this->lastNamespacePrefix );
		} else {
			$searcher->updateNamespacesFromQuery( $term );
		}
		$highlightingConfig = FullTextResultsType::HIGHLIGHT_ALL;
		if ( $this->request ) {
			if ( $this->request->getVal( 'cirrusSuppressSuggest' ) !== null ) {
				$this->showSuggestion = false;
			}
			if ( $this->request->getVal( 'cirrusSuppressTitleHighlight' ) !== null ) {
				$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_TITLE;
			}
			if ( $this->request->getVal( 'cirrusSuppressAltTitle' ) !== null ) {
				$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_ALT_TITLE;
			}
			if ( $this->request->getVal( 'cirrusSuppressSnippet' ) !== null ) {
				$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_SNIPPET;
			}
			if ( $this->request->getVal( 'cirrusHighlightDefaultSimilarity' ) === 'no' ) {
				$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_WITH_DEFAULT_SIMILARITY;
			}
			if ( $this->request->getVal( 'cirrusHighlightAltTitleWithPostings' ) === 'no' ) {
				$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_ALT_TITLES_WITH_POSTINGS;
			}
		}
		if ( $this->namespaces && !in_array( NS_FILE, $this->namespaces ) ) {
			$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_FILE_TEXT;
		}

		$resultsType = new FullTextResultsType( $config, $highlightingConfig );
		$searcher->setResultsType( $resultsType );
		$status = $searcher->searchText( $term, $this->showSuggestion );

		$this->lastSearchMetrics = $searcher->getSearchMetrics();

		if ( !$status->isOK() ) {
			return $status;
		}

		$result = $status->getValue();

		// Add interwiki results, if we have a sane result
		// Note that we have no way of sending warning back to the user.  In this case all warnings
		// are logged when they are added to the status object so we just ignore them here....
		if ( $this->isFeatureEnabled( 'interwiki' ) &&
			$searcher->getSearchContext()->areResultsPossible() &&
			( $dumpQuery || $dumpResult || method_exists( $result, 'addInterwikiResults' ) )
		) {

			$iwSearch = new InterwikiSearcher( $this->connection, $config, $this->namespaces );
			$iwSearch->setReturnQuery( $dumpQuery );
			$iwSearch->setDumpResult( $dumpResult );
			$iwSearch->setReturnExplain( $returnExplain );
			$interwikiResults = $iwSearch->getInterwikiResults( $term );

			if ( $interwikiResults !== null ) {
				// If we are dumping we need to convert into an array that can be appended to
				$recallMetrics = [];
				if ( $dumpQuery || $dumpResult ) {
					$result = [$result];
				}
				foreach ( $interwikiResults as $interwiki => $interwikiResult ) {
					$recallMetrics[$interwiki] = "$interwiki:0";
					if ( $dumpQuery || $dumpResult ) {
						$result[] = $interwikiResult;
					} elseif ( $interwikiResult && $interwikiResult->numRows() > 0 ) {
						$recallMetrics[$interwiki] = "$interwiki:" . $interwikiResult->getTotalHits();
						// Hide the search results, we are only
						// running the query for analytic purposes
						if ( $this->config->get( 'CirrusSearchHideCrossProjectResults' ) ) {
							continue;
						}
						$result->addInterwikiResults(
							$interwikiResult, SearchResultSet::SECONDARY_RESULTS, $interwiki
						);
					}
				}
				$this->extraSearchMetrics['wgCirrusSearchCrossProjectRecall'] = implode( '|', $recallMetrics );
				if ( $this->config->get( 'CirrusSearchNewCrossProjectPage' ) &&
					!$this->config->get( 'CirrusSearchHideCrossProjectResults' ) ) {
					$this->features['enable-new-crossproject-page'] = true;
				}
			}
		}

		if ( $dumpQuery || $dumpResult ) {
			$header = null;
			if ( $this->request && $this->request->getVal( 'cirrusExplain' ) === 'pretty' ) {
				$header = 'Content-type: text/html; charset=UTF-8';
				$printer = new CirrusSearch\ExplainPrinter();
				$result = $printer->format( $result );
			} else {
				$header = 'Content-type: application/json; charset=UTF-8';
				if ( $result === null ) {
					$result = '{}';
				} else {
					$result = json_encode( $result, JSON_PRETTY_PRINT );
				}
			}

			if ( $this->dumpAndDie ) {
				// When dumping the query we skip _everything_ but echoing the query.
				RequestContext::getMain()->getOutput()->disable();
				if ( $header !== null ) {
					$this->request->response()->header( $header );
				}
				echo $result;
				exit();
			} else {
				// This breaks the return types and is only used in the fixtures unit tests
				$status->setResult( true, $result );
			}
		}

		return $status;
	}

	/**
	 * Look for suggestions using ES completion suggester.
	 * @param string $search Search string
	 * @param string[]|null $variants Search term variants
	 * @param SearchConfig $config search configuration
	 * @return SearchSuggestionSet Set of suggested names
	 */
	protected function getSuggestions( $search, $variants, SearchConfig $config ) {
		// Inspect features to check if the user selected a specific profile
		$profile = null;
		if ( isset( $this->features[SearchEngine::COMPLETION_PROFILE_TYPE] ) ) {
			$profile = $this->features[SearchEngine::COMPLETION_PROFILE_TYPE];
		}
		$clusterOverride = $config->getElement( 'CirrusSearchClusterOverrides', 'completion' );
		if ( $clusterOverride !== null ) {
			$connection = Connection::getPool( $config, $clusterOverride );
		} else {
			$connection = $this->connection;
		}
		$suggester = new CompletionSuggester( $connection, $this->limit,
				$this->offset, $config, $this->namespaces, null,
				$this->indexBaseName, $profile );

		$response = $suggester->suggest( $search, $variants );
		if ( $response->isOK() ) {
			// Errors will be logged, let's try the exact db match
			return $response->getValue();
		} else {
			return SearchSuggestionSet::emptySuggestionSet();
		}
	}

	/**
	 * Merge the prefix into the query (if any).
	 * @param string $term search term
	 * @return string possibly with a prefix appended
	 */
	public function transformSearchTerm( $term ) {
		if ( $this->prefix != '' ) {
			// Slap the standard prefix notation onto the query
			$term = $term . ' prefix:' . $this->prefix;
		}
		return $term;
	}

	/**
	 * @param string $query
	 * @return string
	 */
	public function replacePrefixes( $query ) {
		$parsed = parent::replacePrefixes( $query );
		if ( $parsed !== $query ) {
			$this->lastNamespacePrefix = substr( $query, 0, strlen( $query ) - strlen( $parsed ) );
		} else {
			$this->lastNamespacePrefix = '';
		}
		return $parsed;
	}

	/**
	 * Get the sort of sorts we allow
	 * @return string[]
	 */
	public function getValidSorts() {
		return [ 'relevance', 'title_asc', 'title_desc' ];
	}

	/**
	 * Get the metrics for the last search we performed. Null if we haven't done any.
	 * @return array
	 */
	public function getLastSearchMetrics() {
		/** @suppress PhanTypeMismatchReturn Phan doesn't handle array addition correctly */
		return $this->lastSearchMetrics + $this->extraSearchMetrics;
	}

	protected function completionSuggesterEnabled( SearchConfig $config ) {
		$useCompletion = $config->getElement( 'CirrusSearchUseCompletionSuggester' );
		if( $useCompletion !== 'yes' && $useCompletion !== 'beta' ) {
			return false;
		}

		// This way API can force-enable completion suggester
		if ( $this->isFeatureEnabled( self::COMPLETION_SUGGESTER_FEATURE ) ) {
			return true;
		}

		// Allow falling back to prefix search with query param
		if ( $this->request && $this->request->getVal( 'cirrusUseCompletionSuggester' ) === 'no' ) {
			return false;
		}

		// Allow experimentation with query parameters
		if ( $this->request && $this->request->getVal( 'cirrusUseCompletionSuggester' ) === 'yes' ) {
			return true;
		}

		if ( $useCompletion === 'beta' ) {
			return class_exists( '\BetaFeatures' ) &&
				\BetaFeatures::isFeatureEnabled( $GLOBALS['wgUser'], 'cirrussearch-completionsuggester' );
		}

		return true;
	}

	/**
	 * Perform a completion search.
	 * Does not resolve namespaces and does not check variants.
	 * We use parent search for:
	 * - Special: namespace
	 * We use old prefix search for:
	 * - Suggester not enabled
	 * -
	 * @param string $search
	 * @return SearchSuggestionSet
	 */
	protected function completionSearchBackend( $search ) {
		if ( in_array( NS_SPECIAL, $this->namespaces ) ) {
			// delegate special search to parent
			return parent::completionSearchBackend( $search );
		}

		if ( !$this->completionSuggesterEnabled( $this->config ) ) {
			// Completion suggester is not enabled, fallback to
			// default implementation
			return $this->prefixSearch( $search );
		}

		if ( count( $this->namespaces ) != 1 ||
		     reset( $this->namespaces ) != NS_MAIN ) {
			// for now, suggester only works for main namespace
			return $this->prefixSearch( $search );
		}

		if ( isset( $this->features[SearchEngine::COMPLETION_PROFILE_TYPE] ) ) {
			// Fallback to prefixsearch if the classic profile was selected.
			if ( $this->features[SearchEngine::COMPLETION_PROFILE_TYPE] == self::COMPLETION_PREFIX_FALLBACK_PROFILE ) {
				return $this->prefixSearch( $search );
			}
		}

		// Not really useful, mostly for testing purpose
		$variants = $this->request->getArray( 'cirrusCompletionSuggesterVariant' );
		if ( empty( $variants ) ) {
			global $wgContLang;
			$variants = $wgContLang->autoConvertToAllVariants( $search );
		} else if ( count( $variants ) > 3 ) {
			// We should not allow too many variants
			$variants = array_slice( $variants, 0, 3 );
		}

		return $this->getSuggestions( $search, $variants, $this->config );
	}

	/**
	 * Override variants function because we always do variants
	 * in the backend.
	 * @see SearchEngine::completionSearchWithVariants()
	 * @param string $search
	 * @return SearchSuggestionSet
	 */
	public function completionSearchWithVariants( $search ) {
		return $this->completionSearch( $search );
	}

	/**
	 * Older prefix search.
	 * @param string $search search text
	 * @return SearchSuggestionSet
	 */
	protected function prefixSearch( $search ) {
		$searcher = new Searcher( $this->connection, $this->offset, $this->limit, $this->config, $this->namespaces );

		if ( $search ) {
			$searcher->setResultsType( new FancyTitleResultsType( $this->config, 'prefix' ) );
		} else {
			// Empty searches always find the title.
			$searcher->setResultsType( new TitleResultsType( $this->config ) );
		}

		try {
			$status = $searcher->prefixSearch( $search );
		} catch ( ApiUsageException $e ) {
			if ( defined( 'MW_API' ) ) {
				throw $e;
			}
			return SearchSuggestionSet::emptySuggestionSet();
		} catch ( UsageException $e ) {
			if ( defined( 'MW_API' ) ) {
				throw $e;
			}
			return SearchSuggestionSet::emptySuggestionSet();
		}

		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// construction site to log errors.
		if ( $status->isOK() ) {
			if ( !$search ) {
				// No need to unpack the simple title matches from non-fancy TitleResultsType
				return SearchSuggestionSet::fromTitles( $status->getValue() );
			}
			$results = array_filter( array_map( function( $match ) {
				if ( isset( $match[ 'titleMatch' ] ) ) {
					return $match[ 'titleMatch' ];
				} else {
					if ( isset( $match[ 'redirectMatches' ][ 0 ] ) ) {
						// TODO maybe dig around in the redirect matches and find the best one?
						return $match[ 'redirectMatches' ][0];
					}
				}
				return false;
			}, $status->getValue() ) );
			return SearchSuggestionSet::fromTitles( $results );
		}

		return SearchSuggestionSet::emptySuggestionSet();
	}

	/**
	 * @param string $profileType
	 * @param User|null $user
	 * @return array|null
	 */
	public function getProfiles( $profileType, User $user = null ) {
		switch( $profileType ) {
		case SearchEngine::COMPLETION_PROFILE_TYPE:
			if ( $this->config->get( 'CirrusSearchUseCompletionSuggester' ) == 'no' ) {
				// No profile selection if completion suggester is disabled.
				return [];
			}

			$userDefault = $this->config->get( 'CirrusSearchCompletionSettings' );
			$allowedProfiles = $this->getAllowedCompletionProfiles();
			// Only check user options if the user is logged to avoid loading
			// default user options.
			if ( $user !== null && $user->getId() !== 0 &&
				$user->getOption( 'cirrussearch-pref-completion-profile' ) !== null &&
				array_key_exists(
					$user->getOption( 'cirrussearch-pref-completion-profile' ),
					$allowedProfiles )
			) {

				$userDefault = $user->getOption( 'cirrussearch-pref-completion-profile' );
			}

			$profiles = [];
			foreach( array_keys( $allowedProfiles ) as $name ) {
				$profiles[] = [
					'name' => $name,
					'desc-message' => 'cirrussearch-completion-profile-' . $name,
					'default' => $userDefault === $name,
				];
			}
			return $profiles;
		case SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE:
			$profiles = [];
			// The Hook should run profiles/RescoreProfiles.php before
			// any consumer call to getProfiles, so that the default
			// will be properly set when the curstom request param
			// cirrusRescoreProfile=profile is set.
			$cirrusDefault = $this->config->get( 'CirrusSearchRescoreProfile' );
			$defaultFound = false;
			foreach( $this->config->get( 'CirrusSearchRescoreProfiles' ) as $name => $profile ) {
				$default = $cirrusDefault === $name;
				$defaultFound |= $default;
				$profiles[] = [
					'name' => $name,
					// @todo: decide what to with profiles we declare
					// in wmf-config with no i18n messages.
					// Do we want to expose them anyway, or simply
					// hide them but still allow Api to pass them to us.
					// It may require a change in core since ApiBase is
					// strict and won't allow unknown values to be set
					// here.
					'desc-message' => isset ( $profile['i18n_msg'] ) ? $profile['i18n_msg'] : null,
					'default' => $default,
				];
			}
			return $profiles;
		}
		return null;
	}

	/**
	 * Return the list of completion profiles allowed
	 * @return array[] list of profiles indexed by profile name
	 */
	private function getAllowedCompletionProfiles() {
		$profiles = [];
		$allowedFields = [ 'suggest' => true, 'suggest-stop' => true ];
		// Check that we can use the subphrases FST
		if ( $this->config->getElement( 'CirrusSearchCompletionSuggesterSubphrases', 'use' ) ) {
			$allowedFields['suggest-subphrases'] = true;
		}
		foreach( $this->config->get( 'CirrusSearchCompletionProfiles' ) as $name => $settings ) {
			$allowed = true;
			foreach( $settings as $value ) {
				if ( !array_key_exists( $value['field'], $allowedFields ) ) {
					$allowed = false;
					break;
				}
			}
			if ( !$allowed ) {
				continue;
			}
			$profiles[$name] = $settings;
		}
		// Add fallback to prefixsearch
		$profiles[self::COMPLETION_PREFIX_FALLBACK_PROFILE] = [];
		return $profiles;
	}

	/**
	 * Create a search field definition
	 * @param string $name
	 * @param int    $type
	 * @return SearchIndexField
	 */
	public function makeSearchFieldMapping( $name, $type ) {
		return $this->searchIndexFieldFactory->makeSearchFieldMapping( $name, $type );
	}

	/**
	 * @param bool $dumpAndDie Sets the behaviour for the dump query, dump
	 *  result, etc debugging features. By default echo's and dies. If this is
	 *  set to false it will be returned from Searcher::searchText, which breaks
	 *  the type signature contracts and should only be used from unit tests.
	 */
	public function setDumpAndDie( $dumpAndDie ) {
		$this->dumpAndDie = $dumpAndDie;
	}
}
