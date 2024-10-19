<?php

namespace CirrusSearch;

use CirrusSearch\Search\FancyTitleResultsType;
use HtmlArmor;
use ISearchResultSet;
use MediaWiki\Actions\ActionEntryPoint;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiOpenSearch;
use MediaWiki\Api\Hook\APIAfterExecuteHook;
use MediaWiki\Api\Hook\APIQuerySiteInfoStatisticsInfoHook;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\ApiBeforeMainHook;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Hook\SoftwareInfoHook;
use MediaWiki\Hook\SpecialSearchResultsAppendHook;
use MediaWiki\Hook\SpecialSearchResultsHook;
use MediaWiki\Hook\SpecialStatsAddExtraHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Request\WebRequest;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;
use MediaWiki\Search\Hook\PrefixSearchExtractNamespaceHook;
use MediaWiki\Search\Hook\SearchGetNearMatchHook;
use MediaWiki\Search\Hook\ShowSearchHitTitleHook;
use MediaWiki\Specials\SpecialSearch;
use MediaWiki\Title\Title;
use MediaWiki\User\Hook\UserGetDefaultOptionsHook;
use MediaWiki\User\User;
use SearchResult;

/**
 * All CirrusSearch's external hooks.
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
class Hooks implements
	UserGetDefaultOptionsHook,
	GetPreferencesHook,
	APIAfterExecuteHook,
	ApiBeforeMainHook,
	APIQuerySiteInfoStatisticsInfoHook,
	BeforeInitializeHook,
	PrefixSearchExtractNamespaceHook,
	ResourceLoaderGetConfigVarsHook,
	SearchGetNearMatchHook,
	ShowSearchHitTitleHook,
	SoftwareInfoHook,
	SpecialSearchResultsHook,
	SpecialSearchResultsAppendHook,
	SpecialStatsAddExtraHook
{
	/** @var ConfigFactory */
	private $configFactory;

	/**
	 * @param ConfigFactory $configFactory
	 */
	public function __construct( ConfigFactory $configFactory ) {
		$this->configFactory = $configFactory;
	}

	/**
	 * Hooked to call initialize after the user is set up.
	 *
	 * @param Title $title
	 * @param null $unused
	 * @param OutputPage $outputPage
	 * @param User $user
	 * @param WebRequest $request
	 * @param ActionEntryPoint $mediaWiki
	 */
	public function onBeforeInitialize( $title, $unused, $outputPage, $user, $request, $mediaWiki ) {
		self::initializeForRequest( $request );
	}

	/**
	 * Hooked to call initialize after the user is set up.
	 * @param ApiMain &$apiMain The ApiMain instance being used
	 */
	public function onApiBeforeMain( &$apiMain ) {
		self::initializeForRequest( $apiMain->getRequest() );
	}

	/**
	 * Initializes the portions of Cirrus that require the $request to be fully initialized
	 *
	 * @param WebRequest $request
	 */
	public static function initializeForRequest( WebRequest $request ) {
		global $wgCirrusSearchPhraseRescoreWindowSize,
			$wgCirrusSearchFunctionRescoreWindowSize,
			$wgCirrusSearchFragmentSize,
			$wgCirrusSearchPhraseRescoreBoost,
			$wgCirrusSearchPhraseSlop,
			$wgCirrusSearchLogElasticRequests,
			$wgCirrusSearchLogElasticRequestsSecret,
			$wgCirrusSearchEnableAltLanguage,
			$wgCirrusSearchUseCompletionSuggester;

		self::overrideMoreLikeThisOptionsFromMessage();

		self::overrideNumeric( $wgCirrusSearchPhraseRescoreWindowSize,
			$request, 'cirrusPhraseWindow', 10000 );
		self::overrideNumeric( $wgCirrusSearchPhraseRescoreBoost,
			$request, 'cirrusPhraseBoost' );
		self::overrideNumeric( $wgCirrusSearchPhraseSlop[ 'boost' ],
			$request, 'cirrusPhraseSlop', 10 );
		self::overrideNumeric( $wgCirrusSearchFunctionRescoreWindowSize,
			$request, 'cirrusFunctionWindow', 10000 );
		self::overrideNumeric( $wgCirrusSearchFragmentSize,
			$request, 'cirrusFragmentSize', 1000 );
		if ( $wgCirrusSearchUseCompletionSuggester === 'yes' || $wgCirrusSearchUseCompletionSuggester === true ) {
			// Only allow disabling the completion suggester, enabling it from request params might cause failures
			// as the index might not be present.
			self::overrideYesNo( $wgCirrusSearchUseCompletionSuggester,
				$request, 'cirrusUseCompletionSuggester' );
		}
		self::overrideMoreLikeThisOptions( $request );
		self::overrideSecret( $wgCirrusSearchLogElasticRequests,
			$wgCirrusSearchLogElasticRequestsSecret, $request, 'cirrusLogElasticRequests', false );
		self::overrideYesNo( $wgCirrusSearchEnableAltLanguage,
			$request, 'cirrusAltLanguage' );
	}

	/**
	 * Set $dest to the numeric value from $request->getVal( $name ) if it is <= $limit
	 * or => $limit if upperLimit is false.
	 *
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 * @param int|null $limit
	 * @param bool $upperLimit
	 */
	private static function overrideNumeric(
		&$dest,
		WebRequest $request,
		$name,
		$limit = null,
		$upperLimit = true
	) {
		Util::overrideNumeric( $dest, $request, $name, $limit, $upperLimit );
	}

	/**
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 */
	private static function overrideMinimumShouldMatch( &$dest, WebRequest $request, $name ) {
		$val = $request->getVal( $name );
		if ( $val !== null && self::isMinimumShouldMatch( $val ) ) {
			$dest = $val;
		}
	}

	/**
	 * Set $dest to $value when $request->getVal( $name ) contains $secret
	 *
	 * @param mixed &$dest
	 * @param string $secret
	 * @param WebRequest $request
	 * @param string $name
	 * @param mixed $value
	 */
	private static function overrideSecret( &$dest, $secret, WebRequest $request, $name, $value = true ) {
		if ( $secret && $secret === $request->getVal( $name ) ) {
			$dest = $value;
		}
	}

	/**
	 * Set $dest to the true/false from $request->getVal( $name ) if yes/no.
	 *
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 */
	private static function overrideYesNo( &$dest, WebRequest $request, $name ) {
		Util::overrideYesNo( $dest, $request, $name );
	}

	/**
	 * Extract more like this settings from the i18n message cirrussearch-morelikethis-settings
	 */
	private static function overrideMoreLikeThisOptionsFromMessage() {
		global $wgCirrusSearchMoreLikeThisConfig,
			$wgCirrusSearchMoreLikeThisAllowedFields,
			$wgCirrusSearchMoreLikeThisMaxQueryTermsLimit,
			$wgCirrusSearchMoreLikeThisFields;

		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		$lines = $cache->getWithSetCallback(
			$cache->makeKey( 'cirrussearch-morelikethis-settings' ),
			600,
			static function () {
				$source = wfMessage( 'cirrussearch-morelikethis-settings' )->inContentLanguage();
				if ( $source->isDisabled() ) {
					return [];
				}
				return Util::parseSettingsInMessage( $source->plain() );
			}
		);

		foreach ( $lines as $line ) {
			if ( strpos( $line, ':' ) === false ) {
				continue;
			}
			[ $k, $v ] = explode( ':', $line, 2 );
			switch ( $k ) {
				case 'min_doc_freq':
				case 'max_doc_freq':
				case 'max_query_terms':
				case 'min_term_freq':
				case 'min_word_length':
				case 'max_word_length':
					if ( is_numeric( $v ) && $v >= 0 ) {
						$wgCirrusSearchMoreLikeThisConfig[$k] = intval( $v );
					} elseif ( $v === 'null' ) {
						unset( $wgCirrusSearchMoreLikeThisConfig[$k] );
					}
					break;
				case 'percent_terms_to_match':
					// @deprecated Use minimum_should_match now
					$k = 'minimum_should_match';
					if ( is_numeric( $v ) && $v > 0 && $v <= 1 ) {
						$v = ( (int)( (float)$v * 100 ) ) . '%';
					} else {
						break;
					}
					// intentional fall-through
				case 'minimum_should_match':
					if ( self::isMinimumShouldMatch( $v ) ) {
						$wgCirrusSearchMoreLikeThisConfig[$k] = $v;
					} elseif ( $v === 'null' ) {
						unset( $wgCirrusSearchMoreLikeThisConfig[$k] );
					}
					break;
				case 'fields':
					$wgCirrusSearchMoreLikeThisFields = array_intersect(
						array_map( 'trim', explode( ',', $v ) ),
						$wgCirrusSearchMoreLikeThisAllowedFields );
					break;
			}
			// @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset
			if ( $wgCirrusSearchMoreLikeThisConfig['max_query_terms'] > $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit ) {
				$wgCirrusSearchMoreLikeThisConfig['max_query_terms'] = $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit;
			}
		}
	}

	/**
	 * @param string $v The value to check
	 * @return bool True if $v is an integer percentage in the domain -100 <= $v <= 100, $v != 0
	 * @todo minimum_should_match also supports combinations (3<90%) and multiple combinations
	 */
	private static function isMinimumShouldMatch( string $v ) {
		// specific integer count > 0
		if ( ctype_digit( $v ) && $v != 0 ) {
			return true;
		}
		// percentage 0 < x <= 100
		if ( !str_ends_with( $v, '%' ) ) {
			return false;
		}
		$v = substr( $v, 0, -1 );
		if ( str_starts_with( $v, '-' ) ) {
			$v = substr( $v, 1 );
		}
		return ctype_digit( $v ) && $v > 0 && $v <= 100;
	}

	/**
	 * Override more like this settings from request URI parameters
	 *
	 * @param WebRequest $request
	 */
	private static function overrideMoreLikeThisOptions( WebRequest $request ) {
		global $wgCirrusSearchMoreLikeThisConfig,
			$wgCirrusSearchMoreLikeThisAllowedFields,
			$wgCirrusSearchMoreLikeThisMaxQueryTermsLimit,
			$wgCirrusSearchMoreLikeThisFields;

		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_doc_freq'],
			$request, 'cirrusMltMinDocFreq' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_doc_freq'],
			$request, 'cirrusMltMaxDocFreq' );
		// @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_query_terms'],
			$request, 'cirrusMltMaxQueryTerms', $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_term_freq'],
			$request, 'cirrusMltMinTermFreq' );
		self::overrideMinimumShouldMatch( $wgCirrusSearchMoreLikeThisConfig['minimum_should_match'],
			$request, 'cirrusMltMinimumShouldMatch' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_word_length'],
			$request, 'cirrusMltMinWordLength' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_word_length'],
			$request, 'cirrusMltMaxWordLength' );
		$fields = $request->getVal( 'cirrusMltFields' );
		if ( isset( $fields ) ) {
			$wgCirrusSearchMoreLikeThisFields = array_intersect(
				array_map( 'trim', explode( ',', $fields ) ),
				$wgCirrusSearchMoreLikeThisAllowedFields );
		}
	}

	/**
	 * Hook called to include Elasticsearch version info on Special:Version
	 * @param array &$software Array of wikitext and version numbers
	 */
	public function onSoftwareInfo( &$software ) {
		$version = new Version( self::getConnection() );
		$status = $version->get();
		if ( $status->isOK() ) {
			// We've already logged if this isn't ok and there is no need to warn the user on this page.
			$software[ '[https://www.elastic.co/elasticsearch Elasticsearch]' ] = $status->getValue();
		}
	}

	/**
	 * @param SpecialSearch $specialSearch
	 * @param OutputPage $out
	 * @param string $term
	 */
	public function onSpecialSearchResultsAppend( $specialSearch, $out, $term ) {
		$feedbackLink = $out->getConfig()->get( 'CirrusSearchFeedbackLink' );

		if ( $feedbackLink ) {
			self::addSearchFeedbackLink( $feedbackLink, $specialSearch, $out );
		}

		// Embed metrics if this was a Cirrus page
		$engine = $specialSearch->getSearchEngine();
		if ( $engine instanceof CirrusSearch ) {
			$out->addJsConfigVars( $engine->getLastSearchMetrics() );
		}
	}

	/**
	 * @param string $link
	 * @param SpecialSearch $specialSearch
	 * @param OutputPage $out
	 */
	private static function addSearchFeedbackLink( $link, SpecialSearch $specialSearch, OutputPage $out ) {
		$anchor = Html::element(
			'a',
			[ 'href' => $link ],
			$specialSearch->msg( 'cirrussearch-give-feedback' )->text()
		);
		$block = Html::rawElement( 'div', [], $anchor );
		$out->addHTML( $block );
	}

	/**
	 * Extract namespaces from query string.
	 * @param array &$namespaces
	 * @param string &$search
	 * @return bool
	 */
	public function onPrefixSearchExtractNamespace( &$namespaces, &$search ) {
		global $wgSearchType;
		if ( $wgSearchType !== 'CirrusSearch' ) {
			return true;
		}
		return self::prefixSearchExtractNamespaceWithConnection( self::getConnection(), $namespaces, $search );
	}

	/**
	 * @param Connection $connection
	 * @param array &$namespaces
	 * @param string &$search
	 * @return false
	 */
	public static function prefixSearchExtractNamespaceWithConnection(
		Connection $connection,
		&$namespaces,
		&$search
	) {
		$method = $connection->getConfig()->get( 'CirrusSearchNamespaceResolutionMethod' );
		if ( $method === 'elastic' ) {
			$searcher =
				new Searcher( $connection, 0, 1, $connection->getConfig(), $namespaces );
			$searcher->updateNamespacesFromQuery( $search );
			$namespaces = $searcher->getSearchContext()->getNamespaces();
		} else {
			$colon = strpos( $search, ':' );
			if ( $colon === false ) {
				return false;
			}
			$namespaceName = substr( $search, 0, $colon );
			$ns = Util::identifyNamespace( $namespaceName, $method );
			if ( $ns !== false ) {
				$namespaces = [ $ns ];
				$search = substr( $search, $colon + 1 );
			}
		}

		return false;
	}

	public function onSearchGetNearMatch( $term, &$titleResult ) {
		return self::handleSearchGetNearMatch( $term, $titleResult );
	}

	/**
	 * Let Elasticsearch take a crack at getting near matches once mediawiki has tried all kinds of variants.
	 * @param string $term the original search term and all language variants
	 * @param null|Title &$titleResult resulting match.  A Title if we found something, unchanged otherwise.
	 * @return bool return false if we find something, true otherwise so mediawiki can try its default behavior
	 */
	public static function handleSearchGetNearMatch( $term, &$titleResult ) {
		global $wgSearchType;
		if ( $wgSearchType !== 'CirrusSearch' ) {
			return true;
		}

		$title = Title::newFromText( $term );
		if ( $title === null ) {
			return false;
		}

		$user = RequestContext::getMain()->getUser();
		// Ask for the first 50 results we see.  If there are more than that too bad.
		$searcher = new Searcher(
			self::getConnection(), 0, 50, self::getConfig(), [ $title->getNamespace() ], $user );
		if ( $title->getNamespace() === NS_MAIN ) {
			$searcher->updateNamespacesFromQuery( $term );
		} else {
			$term = $title->getText();
		}
		$searcher->setResultsType( new FancyTitleResultsType( 'near_match' ) );
		$status = $searcher->nearMatchTitleSearch( $term );
		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// construction site to log errors.
		if ( !$status->isOK() ) {
			return true;
		}

		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$picker = new NearMatchPicker( $contLang, $term, $status->getValue() );
		$best = $picker->pickBest();
		if ( $best ) {
			$titleResult = $best;
			return false;
		}
		// Didn't find a result so let MediaWiki have a crack at it.
		return true;
	}

	/**
	 * ResourceLoaderGetConfigVars hook handler
	 * This should be used for variables which vary with the html
	 * and for variables this should work cross skin
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderGetConfigVars
	 *
	 * @param array &$vars
	 * @param string $skin
	 * @param Config $config
	 */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, Config $config ): void {
		$vars += [
			'wgCirrusSearchFeedbackLink' => $config->get( 'CirrusSearchFeedbackLink' ),
		];
	}

	/**
	 * @return SearchConfig
	 */
	private static function getConfig() {
		// @phan-suppress-next-line PhanTypeMismatchReturnSuperType
		return MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
	}

	/**
	 * @return Connection
	 */
	private static function getConnection() {
		return new Connection( self::getConfig() );
	}

	/**
	 * Add $wgCirrusSearchInterwikiProv to external results.
	 * @param Title &$title
	 * @param string|HtmlArmor|null &$text
	 * @param SearchResult $result
	 * @param array $terms
	 * @param SpecialSearch $page
	 * @param string[] &$query
	 * @param string[] &$attributes
	 */
	public function onShowSearchHitTitle( &$title, &$text, $result, $terms, $page, &$query, &$attributes ) {
		global $wgCirrusSearchInterwikiProv;
		if ( $wgCirrusSearchInterwikiProv && $title->isExternal() ) {
			$query["wprov"] = $wgCirrusSearchInterwikiProv;
		}
	}

	/**
	 * @param ApiBase $module
	 */
	public function onAPIAfterExecute( $module ) {
		if ( !ElasticsearchIntermediary::hasQueryLogs() ) {
			return;
		}
		$response = $module->getContext()->getRequest()->response();
		$response->header( 'X-Search-ID: ' . Util::getRequestSetToken() );
		if ( $module instanceof ApiOpenSearch ) {
			$types = ElasticsearchIntermediary::getQueryTypesUsed();
			if ( $types ) {
				$response->header( 'X-OpenSearch-Type: ' . implode( ',', $types ) );
			}
		}
	}

	/**
	 * @param string $term
	 * @param ISearchResultSet|null &$titleMatches
	 * @param ISearchResultSet|null &$textMatches
	 */
	public function onSpecialSearchResults( $term, &$titleMatches, &$textMatches ) {
		$context = RequestContext::getMain();
		$out = $context->getOutput();

		$out->addModules( 'ext.cirrus.serp' );

		$jsVars = [
			'wgCirrusSearchRequestSetToken' => Util::getRequestSetToken(),
		];
		// In theory UserTesting should always have been activated by now, but if
		// somehow it wasn't we don't want to activate it now at the end of the request
		// and report incorrect data.
		if ( UserTestingStatus::hasInstance() ) {
			$ut = UserTestingStatus::getInstance();
			if ( $ut->isActive() ) {
				$trigger = $ut->getTrigger();
				$jsVars['wgCirrusSearchActiveUserTest'] = $trigger;
				// bc for first deployment, some users will still have old js.
				// Should be removed in following deployment.
				$jsVars['wgCirrusSearchBackendUserTests'] = $trigger ? [ $trigger ] : [];
			}
		}
		$out->addJsConfigVars( $jsVars );

		// This ignores interwiki results for now...not sure what do do with those
		ElasticsearchIntermediary::setResultPages( [
			$titleMatches,
			$textMatches
		] );
	}

	/**
	 * @param array &$extraStats
	 * @return void
	 */
	private static function addWordCount( array &$extraStats ): void {
		$search = new CirrusSearch();

		$status = $search->countContentWords();
		if ( !$status->isOK() ) {
			return;
		}
		$wordCount = $status->getValue();
		if ( $wordCount !== null ) {
			$extraStats['cirrussearch-article-words'] = $wordCount;
		}
	}

	/** @inheritDoc */
	public function onGetPreferences( $user, &$prefs ) {
		$search = new CirrusSearch();
		$profiles = $search->getProfiles( \SearchEngine::COMPLETION_PROFILE_TYPE, $user );
		if ( !$profiles ) {
			return;
		}
		$options = self::autoCompleteOptionsForPreferences( $profiles );
		if ( !$options ) {
			return;
		}
		$prefs['cirrussearch-pref-completion-profile'] = [
			'type' => 'radio',
			'section' => 'searchoptions/completion',
			'options' => $options,
			'label-message' => 'cirrussearch-pref-completion-profile-help',
		];
	}

	/**
	 * @param array[] $profiles
	 * @return string[]
	 */
	private static function autoCompleteOptionsForPreferences( array $profiles ): array {
		$available = array_column( $profiles, 'name' );
		// Order in which we propose comp suggest profiles
		$preferredOrder = [
			'fuzzy',
			'fuzzy-subphrases',
			'strict',
			'normal',
			'normal-subphrases',
			'classic'
		];
		$messages = [];
		foreach ( $preferredOrder as $name ) {
			if ( in_array( $name, $available ) ) {
				$display = wfMessage( "cirrussearch-completion-profile-$name-pref-name" )->escaped() .
					new \OOUI\LabelWidget( [
						'classes' => [ 'oo-ui-inline-help' ],
						'label' => wfMessage( "cirrussearch-completion-profile-$name-pref-desc" )->text()
					] );
				$messages[$display] = $name;
			}
		}
		// At least 2 choices are required to provide the user a choice
		return count( $messages ) >= 2 ? $messages : [];
	}

	/** @inheritDoc */
	public function onUserGetDefaultOptions( &$defaultOptions ) {
		$defaultOptions['cirrussearch-pref-completion-profile'] =
			$this->configFactory->makeConfig( 'CirrusSearch' )->get( 'CirrusSearchCompletionSettings' );
	}

	public function onSpecialStatsAddExtra( &$extraStats, $context ) {
		self::addWordCount( $extraStats );
	}

	public function onAPIQuerySiteInfoStatisticsInfo( &$extraStats ) {
		self::addWordCount( $extraStats );
	}
}
