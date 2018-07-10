<?php

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\InterwikiSearcher;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\SearchMetricsProvider;
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

	/**
	 * Special profile to instruct this class to use profile
	 * selection mechanism.
	 * This allows to defer profile selection to when we actually perform
	 * the search. The reason is that the list of possible profiles
	 * is returned by self::getProfiles so instead of assigning a default
	 * profile at this point we use this special profile.
	 */
	const AUTOSELECT_PROFILE = 'engine_autoselect';

	/** @const string name of the prefixsearch fallback profile */
	const COMPLETION_PREFIX_FALLBACK_PROFILE = 'classic';

	/**
	 * @const int Maximum title length that we'll check in prefix and keyword searches.
	 * Since titles can be 255 bytes in length we're setting this to 255
	 * characters.
	 */
	const MAX_TITLE_SEARCH = 255;

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
	 * @var SearchConfig immutable
	 */
	private $config;

	/**
	 * Current request.
	 * @var WebRequest
	 */
	private $request;

	/**
	 * @var CirrusSearchIndexFieldFactory
	 */
	private $searchIndexFieldFactory;

	/**
	 * @var CirrusDebugOptions
	 */
	private $debugOptions;
	/**
	 * CirrusSearch constructor.
	 * @param string|null $baseName
	 * @param SearchConfig|null $config
	 * @param CirrusDebugOptions|null $debugOptions
	 * @throws ConfigException
	 */
	public function __construct( $baseName = null, SearchConfig $config = null, CirrusDebugOptions $debugOptions = null ) {
		// Initialize UserTesting before we create a Connection
		// This is useful to do tests accross multiple clusters
		UserTesting::getInstance();
		$this->config = $config ?? MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		$this->indexBaseName = $baseName ?? $this->config->get( SearchConfig::INDEX_BASE_NAME );
		$this->connection = new Connection( $this->config );
		$this->request = RequestContext::getMain()->getRequest();
		$this->searchIndexFieldFactory = new CirrusSearchIndexFieldFactory( $this->config );

		// enable interwiki by default
		$this->features['interwiki'] = true;
		$this->debugOptions = $debugOptions ?? CirrusDebugOptions::fromRequest( $this->request );
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
	protected function doSearchText( $term ) {
		$status = $this->searchTextReal( $term, $this->config );
		$matches = $status->getValue();
		if ( !$status->isOK() || !$matches instanceof ResultSet ) {
			return $status;
		}

		if ( $this->isFeatureEnabled( 'rewrite' ) &&
			 $matches->isQueryRewriteAllowed( $GLOBALS['wgCirrusSearchInterwikiThreshold'] ) &&
			 $this->prefix === ''
		) {
			$status = $this->searchTextSecondTry( $term, $status );
		}
		ElasticsearchIntermediary::setResultPages( [ $status->getValue() ] );
		if ( $status->getValue() instanceof SearchMetricsProvider ) {
			$this->extraSearchMetrics += $status->getValue()->getMetrics();
		}

		return $status;
	}

	/**
	 * Check whether we want to try another language.
	 * @param string $term Search term
	 * @return SearchConfig|null config for another wiki to try, or null
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
			if ( !( $detector instanceof \CirrusSearch\LanguageDetector\Detector ) ) {
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
			if ( $lang === $this->config->get( 'LanguageCode' ) ) {
				// The query is in the wiki language so we
				// don't need to actually try another wiki.
				// Note that this may not be very accurate for
				// wikis that use deprecated language codes
				// but the interwiki resolver should not return
				// ourselves.
				continue;
			}
			$iwPrefixAndConfig = MediaWikiServices::getInstance()
				->getService( InterwikiResolver::SERVICE )
				->getSameProjectConfigByLang( $lang );
			if ( !empty( $iwPrefixAndConfig ) ) {
				// it might be more accurate to attach these to the 'next'
				// log context? It would be inconsistent with the
				// langdetect => false condition which does not have a next
				// request though.
				Searcher::appendLastLogPayload( 'langdetect', $name );
				$detected = $iwPrefixAndConfig;
				break;
			}
		}
		if ( is_array( $detected ) ) {
			// Report language detection with search metrics
			// TODO: do we still need this metric? (see T151796)
			reset( $detected );
			$prefix = key( $detected );
			$config = $detected[$prefix];
			$metric = [ $config->getWikiId(), $prefix ];
			$this->extraSearchMetrics['wgCirrusSearchAltLanguage'] = $metric;
			return $config;
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
		$config = $this->detectSecondaryLanguage( $term );
		if ( $config !== null ) {
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
				if ( $GLOBALS['wgCirrusSearchEnableAltLanguage'] && $numRows > 0 ) {
					$oldResult->addInterwikiResults( $matches, SearchResultSet::INLINE_RESULTS, $config->getWikiId() );
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
	 * @param bool $forceLocal set to true to force searching on the
	 *        local wiki (e.g. avoid searching on commons)
	 * @return Status
	 */
	protected function searchTextReal( $term, SearchConfig $config, $forceLocal = false ) {
		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		// TODO: move this to the parser
		$tildePrefix = false;
		if ( substr( $term, 0, 1 ) === '~' ) {
			$term = substr( $term, 1 );
			$tildePrefix = true;
		}

		// TODO: move this to the parser
		$queryAndNs = self::parseNamespacePrefixes( $term, true, true );
		$nsPrefix = null;
		if ( $queryAndNs !== false ) {
			$this->namespaces = $queryAndNs[1];
			$term = $queryAndNs[0];
			$nsPrefix = substr( $term, 0, strlen( $term ) - strlen( $term ) );
		}

		$searcher = $this->makeSearcher( $config );
		if ( $tildePrefix ) {
			$searcher->addSuggestPrefix( '~' );
		}

		if ( $nsPrefix !== null ) {
			$searcher->addSuggestPrefix( $nsPrefix );
		}

		if ( $this->prefix !== '' ) {
			// TODO: move this to the query building code
			\CirrusSearch\Query\PrefixFeature::prepareSearchContext( $searcher->getSearchContext(), $this->prefix );
		}

		$searcher->getSearchContext()->setLimitSearchToLocalWiki( $forceLocal );
		$searcher->setSort( $this->getSort() );

		$profile = $this->extractProfileFromFeatureData( SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE );
		if ( $profile !== null ) {
			$searcher->getSearchContext()->setRescoreProfile( $profile );
		}

		$searcher->setResultsType( new FullTextResultsType() );
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
			$searcher->getSearchContext()->getSearchComplexity() <= InterwikiSearcher::MAX_COMPLEXITY &&
			$config->isCrossProjectSearchEnabled() &&
			( $this->debugOptions->isReturnRaw() || method_exists( $result, 'addInterwikiResults' ) )
		) {
			$iwSearch = new InterwikiSearcher( $this->connection, $config, $this->namespaces, null, $this->debugOptions );
			$interwikiResults = $iwSearch->getInterwikiResults( $term );
			if ( $interwikiResults !== null ) {
				// If we are dumping we need to convert into an array that can be appended to
				if ( $this->debugOptions->isReturnRaw() ) {
					$result = [ $result ];
				}
				foreach ( $interwikiResults as $interwiki => $interwikiResult ) {
					if ( $this->debugOptions->isReturnRaw() ) {
						$result[] = $interwikiResult;
					} elseif ( $interwikiResult && $interwikiResult->numRows() > 0 ) {
						$result->addInterwikiResults(
							$interwikiResult, SearchResultSet::SECONDARY_RESULTS, $interwiki
						);
					}
				}
			}
		}

		if ( $this->debugOptions->isReturnRaw() ) {
			$status->setResult( true,
				$searcher->processRawReturn( $result, $this->request ) );
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
		$profile = $this->extractProfileFromFeatureData( SearchEngine::COMPLETION_PROFILE_TYPE );

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
	 * @param string $query
	 * @return string
	 * @deprecated will be removed soon
	 */
	public function replacePrefixes( $query ) {
		return $query;
	}

	/**
	 * Get the sort of sorts we allow
	 * @return string[]
	 */
	public function getValidSorts() {
		return [
			'relevance', 'just_match', 'none',
			'incoming_links_asc', 'incoming_links_desc',
			'last_edit_asc', 'last_edit_desc',
			// This should not be exposed until the indices have been populated
			// 'create_timestamp_asc', 'create_timestamp_desc',
		];
	}

	/**
	 * Get the metrics for the last search we performed. Null if we haven't done any.
	 * @return array
	 */
	public function getLastSearchMetrics() {
		/** @suppress PhanTypeMismatchReturn Phan doesn't handle array addition correctly */
		return $this->lastSearchMetrics + $this->extraSearchMetrics;
	}

	/**
	 * @return bool
	 */
	private function completionSuggesterEnabled() {
		$useCompletion = $this->config->getElement( 'CirrusSearchUseCompletionSuggester' );
		if ( is_string( $useCompletion ) ) {
			return wfStringToBool( $useCompletion );
		}
		return $useCompletion === true;
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

		// Not really useful, mostly for testing purpose
		$variants = $this->debugOptions->getCirrusCompletionVariant();
		if ( empty( $variants ) ) {
			global $wgContLang;
			$variants = $wgContLang->autoConvertToAllVariants( $search );
		} elseif ( count( $variants ) > 3 ) {
			// We should not allow too many variants
			$variants = array_slice( $variants, 0, 3 );
		}

		if ( !$this->completionSuggesterEnabled() ) {
			// Completion suggester is not enabled, fallback to
			// default implementation
			return $this->prefixSearch( $search, $variants );
		}

		// the completion suggester is only worth a try if NS_MAIN is requested
		if ( !in_array( NS_MAIN, $this->namespaces ) ) {
			return $this->prefixSearch( $search, $variants );
		}

		$profile = $this->extractProfileFromFeatureData( SearchEngine::COMPLETION_PROFILE_TYPE );
		if ( $profile === null ) {
			// Need to fetch the name to fallback to prefix (not ideal)
			// We should probably refactor this to have a single code path for prefix and completion suggester.
			$profile = $this->config->getProfileService()
				->loadProfile( SearchProfileService::COMPLETION, SearchProfileService::CONTEXT_DEFAULT, $profile );
		}
		if ( $profile === self::COMPLETION_PREFIX_FALLBACK_PROFILE ) {
			// Fallback to prefixsearch if the classic profile was selected.
			return $this->prefixSearch( $search, $variants );
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
	 * @param string[] $variants
	 * @return SearchSuggestionSet
	 */
	protected function prefixSearch( $search, $variants ) {
		$searcher = $this->makeSearcher();

		if ( $search ) {
			$searcher->setResultsType( new FancyTitleResultsType( 'prefix' ) );
		} else {
			// Empty searches always find the title.
			$searcher->setResultsType( new TitleResultsType() );
		}

		try {
			$status = $searcher->prefixSearch( $search, $variants );
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
			$results = array_filter( array_map(
				[ FancyTitleResultsType::class, 'chooseBestTitleOrRedirect' ],
				$status->getValue() ) );
			return SearchSuggestionSet::fromTitles( $results );
		}

		return SearchSuggestionSet::emptySuggestionSet();
	}

	/**
	 * @param string $profileType
	 * @param User|null $user
	 * @return array|null
	 * @see SearchEngine::getProfiles()
	 */
	public function getProfiles( $profileType, User $user = null ) {
		$profileService = $this->config->getProfileService();
		$serviceProfileType = null;
		switch ( $profileType ) {
		case SearchEngine::COMPLETION_PROFILE_TYPE:
			if ( $this->completionSuggesterEnabled() ) {
				$serviceProfileType = SearchProfileService::COMPLETION;
			}
			break;
		case SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE:
			$serviceProfileType = SearchProfileService::RESCORE;
			break;
		}

		if ( $serviceProfileType === null ) {
			return null;
		}

		$allowedProfiles = $profileService->listExposedProfiles( $serviceProfileType );

		$profiles = [];
		foreach ( $allowedProfiles as $name => $profile ) {
			// @todo: decide what to with profiles we declare
			// in wmf-config with no i18n messages.
			// Do we want to expose them anyway, or simply
			// hide them but still allow Api to pass them to us.
			// It may require a change in core since ApiBase is
			// strict and won't allow unknown values to be set
			// here.
			$profiles[] = [
				'name' => $name,
				'desc-message' => isset( $profile['i18n_msg'] ) ? $profile['i18n_msg'] : null,
			];
		}
		if ( $profiles !== [] ) {
			$profiles[] = [
				'name' => self::AUTOSELECT_PROFILE,
				'desc-message' => 'cirrussearch-autoselect-profile',
				'default' => true,
			];
		}
		return $profiles;
	}

	/**
	 * (public for testing purposes)
	 * @param string $profileType
	 * @return string|null the profile name set in SearchEngine::features
	 * null if none present or equal to self::AUTOSELECT_PROFILE
	 */
	public function extractProfileFromFeatureData( $profileType ) {
		if ( isset( $this->features[$profileType] )
			&& $this->features[$profileType] !== self::AUTOSELECT_PROFILE
		) {
			return $this->features[$profileType];
		}
		return null;
	}

	/**
	 * Create a search field definition
	 * @param string $name
	 * @param int $type
	 * @return SearchIndexField
	 */
	public function makeSearchFieldMapping( $name, $type ) {
		return $this->searchIndexFieldFactory->makeSearchFieldMapping( $name, $type );
	}

	/**
	 * Perform a title search in the article archive.
	 *
	 * @param string $term Raw search term
	 * @return Status<Title[]>
	 */
	public function searchArchiveTitle( $term ) {
		if ( !$this->config->get( 'CirrusSearchEnableArchive' ) ) {
			return Status::newGood( [] );
		}

		$term = trim( $term );

		if ( empty( $term ) ) {
			return Status::newGood( [] );
		}

		$searcher = $this->makeSearcher();
		$status = $searcher->searchArchive( $term );
		if ( $status->isOK() && $searcher->isReturnRaw() ) {
			$status->setResult( true,
				$searcher->processRawReturn( $status->getValue(), $this->request ) );
		}
		return $status;
	}

	/**
	 * @return Status Contains a single integer indicating the number
	 *  of content words in the wiki
	 */
	public function countContentWords() {
		$this->limit = 1;
		$searcher = $this->makeSearcher();
		$status = $searcher->countContentWords();

		if ( $status->isOK() && $searcher->isReturnRaw() ) {
			$status->setResult( true,
				$searcher->processRawReturn( $status->getValue(), $this->request ) );
		}
		return $status;
	}

	/**
	 * @param SearchConfig|null $config
	 * @return Searcher
	 */
	private function makeSearcher( SearchConfig $config = null ) {
		return new Searcher( $this->connection, $this->offset, $this->limit, $config ?? $this->config, $this->namespaces,
				null, $this->indexBaseName, $this->debugOptions );
	}
}
