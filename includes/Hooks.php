<?php

namespace CirrusSearch;

use ApiBase;
use ApiMain;
use ApiOpenSearch;
use CirrusSearch;
use CirrusSearch\Profile\SearchProfileServiceFactory;
use CirrusSearch\Search\FancyTitleResultsType;
use Content;
use DeferredUpdates;
use JobQueueGroup;
use LinksUpdate;
use OutputPage;
use MediaWiki\MediaWikiServices;
use Revision;
use SearchResultSet;
use SpecialSearch;
use Title;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RequestContext;
use ApiUsageException;
use UsageException;
use User;
use WebRequest;
use WikiPage;
use Xml;
use Html;

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
class Hooks {
	/**
	 * @var string[] Destination of titles being moved (the ->getPrefixedDBkey() form).
	 */
	private static $movingTitles = [];

	/**
	 * Hooked to call initialize after the user is set up.
	 *
	 * @param Title $title
	 * @param \Article $unused
	 * @param OutputPage $outputPage
	 * @param User $user
	 * @param \WebRequest $request
	 * @param \MediaWiki $mediaWiki
	 * @return bool
	 */
	public static function onBeforeInitialize( $title, $unused, $outputPage, $user, $request, $mediaWiki ) {
		self::initializeForRequest( $request );
		return true;
	}

	/**
	 * Hooked to call initialize after the user is set up.
	 * @param ApiMain $apiMain The ApiMain instance being used
	 * @return bool
	 */
	public static function onApiBeforeMain( $apiMain ) {
		self::initializeForRequest( $apiMain->getRequest() );
		return true;
	}

	/**
	 * Initializes the portions of Cirrus that require the $request to be fully initialized
	 *
	 * @param WebRequest $request
	 */
	private static function initializeForRequest( WebRequest $request ) {
		global $wgSearchType, $wgHooks,
			$wgCirrusSearchUseExperimentalHighlighter,
			$wgCirrusSearchPhraseRescoreWindowSize,
			$wgCirrusSearchFunctionRescoreWindowSize,
			$wgCirrusSearchFragmentSize,
			$wgCirrusSearchAllFields,
			$wgCirrusSearchAllFieldsForRescore,
			$wgCirrusSearchPhraseRescoreBoost,
			$wgCirrusSearchPhraseSlop,
			$wgCirrusSearchLogElasticRequests,
			$wgCirrusSearchLogElasticRequestsSecret,
			$wgCirrusSearchEnableAltLanguage;

		// Install our prefix search hook only if we're enabled.
		if ( $wgSearchType === 'CirrusSearch' ) {
			$wgHooks[ 'PrefixSearchExtractNamespace' ][] = 'CirrusSearch\Hooks::prefixSearchExtractNamespace';
			$wgHooks[ 'SearchGetNearMatch' ][] = 'CirrusSearch\Hooks::onSearchGetNearMatch';
		}

		self::overrideMoreLikeThisOptionsFromMessage();

		if ( $request ) {
			// Engage the experimental highlighter if a url parameter requests it
			if ( !$wgCirrusSearchUseExperimentalHighlighter &&
					$request->getVal( 'cirrusHighlighter' ) === 'experimental' ) {
				$wgCirrusSearchUseExperimentalHighlighter = true;
			}
			self::overrideNumeric( $wgCirrusSearchPhraseRescoreWindowSize, $request, 'cirrusPhraseWindow', 10000 );
			self::overrideNumeric( $wgCirrusSearchPhraseRescoreBoost, $request, 'cirrusPhraseBoost' );
			self::overrideNumeric( $wgCirrusSearchPhraseSlop[ 'boost' ], $request, 'cirrusPhraseSlop', 10 );
			self::overrideNumeric( $wgCirrusSearchFunctionRescoreWindowSize, $request, 'cirrusFunctionWindow', 10000 );
			self::overrideNumeric( $wgCirrusSearchFragmentSize, $request, 'cirrusFragmentSize', 1000 );
			self::overrideYesNo( $wgCirrusSearchAllFields[ 'use' ], $request, 'cirrusUseAllFields' );
			self::overrideYesNo( $wgCirrusSearchAllFieldsForRescore, $request, 'cirrusUseAllFieldsForRescore' );
			self::overrideUseExtraPluginForRegex( $request );
			self::overrideMoreLikeThisOptions( $request );
			self::overrideSecret( $wgCirrusSearchLogElasticRequests, $wgCirrusSearchLogElasticRequestsSecret, $request, 'cirrusLogElasticRequests', false );
			self::overrideYesNo( $wgCirrusSearchEnableAltLanguage, $request, 'cirrusAltLanguage' );
		}
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
	private static function overrideNumeric( &$dest, WebRequest $request, $name, $limit = null, $upperLimit = true ) {
		Util::overrideNumeric( $dest, $request, $name, $limit, $upperLimit );
	}

	/**
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 */
	private static function overrideMinimumShouldMatch( &$dest, WebRequest $request, $name ) {
		$val = $request->getVal( $name );
		if ( self::isMinimumShouldMatch( $val ) ) {
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
	 * @param WebRequest $request
	 */
	private static function overrideUseExtraPluginForRegex( WebRequest $request ) {
		global $wgCirrusSearchWikimediaExtraPlugin;

		if ( $request->getCheck( 'cirrusAccelerateRegex' ) ) {
			if ( $request->getFuzzyBool( 'cirrusAccelerateRegex' ) ) {
				$wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][] = 'use';
			} elseif ( isset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] ) ) {
				$useLocation = array_search( 'use', $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] );
				if ( $useLocation !== false ) {
					unset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][ $useLocation ] );
				}
			}
		}
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
			function () {
				$source = wfMessage( 'cirrussearch-morelikethis-settings' )->inContentLanguage();
				if ( $source && $source->isDisabled() ) {
					return [];
				}
				return Util::parseSettingsInMessage( $source->plain() );
			}
		);

		foreach ( $lines as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $k, $v ) = explode( ':', $line, 2 );
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
					$v = ( (int)( $v * 100 ) ) . '%';
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
	private static function isMinimumShouldMatch( $v ) {
		// specific integer count > 0
		if ( ctype_digit( $v ) && $v != 0 ) {
			return true;
		}
		// percentage 0 < x <= 100
		if ( substr( $v, -1 ) !== '%' ) {
			return false;
		}
		$v = substr( $v, 0, -1 );
		if ( substr( $v, 0, 1 ) === '-' ) {
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

		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_doc_freq'], $request, 'cirrusMltMinDocFreq' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_doc_freq'], $request, 'cirrusMltMaxDocFreq' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_query_terms'],
			$request, 'cirrusMltMaxQueryTerms', $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_term_freq'], $request, 'cirrusMltMinTermFreq' );
		self::overrideMinimumShouldMatch( $wgCirrusSearchMoreLikeThisConfig['minimum_should_match'], $request, 'cirrusMltMinimumShouldMatch' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_word_length'], $request, 'cirrusMltMinWordLength' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_word_length'], $request, 'cirrusMltMaxWordLength' );
		$fields = $request->getVal( 'cirrusMltFields' );
		if ( isset( $fields ) ) {
			$wgCirrusSearchMoreLikeThisFields = array_intersect(
				array_map( 'trim', explode( ',', $fields ) ),
				$wgCirrusSearchMoreLikeThisAllowedFields );
		}
	}

	/**
	 * Hook to call before an article is deleted
	 * @param WikiPage $page The page we're deleting
	 * @return bool
	 */
	public static function onArticleDelete( $page ) {
		// We use this to pick up redirects so we can update their targets.
		// Can't re-use ArticleDeleteComplete because the page info's
		// already gone
		// If we abort or fail deletion it's no big deal because this will
		// end up being a no-op when it executes.
		$target = $page->getRedirectTarget();
		if ( $target ) {
			// DeferredUpdate so we don't end up racing our own page deletion
			DeferredUpdates::addCallableUpdate( function () use ( $target ) {
				JobQueueGroup::singleton()->push(
					new Job\LinksUpdate( $target, [
						'addedLinks' => [],
						'removedLinks' => [],
					] )
				);
			} );
		}

		return true;
	}

	/**
	 * Hook to call after an article is deleted
	 * @param WikiPage $page The page we're deleting
	 * @param User $user The user deleting the page
	 * @param string $reason Reason the page is being deleted
	 * @param int $pageId Page id being deleted
	 * @return bool
	 */
	public static function onArticleDeleteComplete( $page, $user, $reason, $pageId ) {
		// Note that we must use the article id provided or it'll be lost in the ether.  The job can't
		// load it from the title because the page row has already been deleted.
		JobQueueGroup::singleton()->push(
			new Job\DeletePages( $page->getTitle(), [
				'docId' => self::getConfig()->makeId( $pageId )
			] )
		);
		return true;
	}

	/**
	 * Called when a page is imported. Force a full index of the page. Use the MassIndex
	 * job since there's likely to be a bunch and we'll prioritize them well but use
	 * INDEX_EVERYTHING since we won't get a chance at a second pass.
	 *
	 * @param Title $title The page title we've just imported
	 * @return bool
	 */
	public static function onAfterImportPage( $title ) {
		// The title can be null if the import failed.  Nothing to do in that case.
		if ( $title === null ) {
			return false;
		}
		JobQueueGroup::singleton()->push(
			Job\MassIndex::build(
				[ WikiPage::factory( $title ) ],
				Updater::INDEX_EVERYTHING
			)
		);
		return true;
	}

	/**
	 * Called when a revision is deleted. In theory, we shouldn't need to to this since
	 * you can't delete the current text of a page (so we should've already updated when
	 * the page was updated last). But we're paranoid, because deleted revisions absolutely
	 * should not be in the index.
	 *
	 * @param Title $title The page title we've had a revision deleted on
	 * @return bool
	 */
	public static function onRevisionDelete( $title ) {
		JobQueueGroup::singleton()->push(
			new Job\LinksUpdate( $title, [
				'addedLinks' => [],
				'removedLinks' => [],
				'prioritize' => true
			] )
		);
		return true;
	}

	/**
	 * Hook called to include Elasticsearch version info on Special:Version
	 * @param array &$software Array of wikitext and version numbers
	 * @return bool
	 */
	public static function onSoftwareInfo( &$software ) {
		$version = new Version( self::getConnection() );
		$status = $version->get();
		if ( $status->isOK() ) {
			// We've already logged if this isn't ok and there is no need to warn the user on this page.
			$software[ '[https://www.elastic.co/products/elasticsearch Elasticsearch]' ] = $status->getValue();
		}
		return true;
	}

	/**
	 * @param SpecialSearch $specialSearch
	 * @param OutputPage $out
	 * @param string $term
	 * @return bool
	 */
	public static function onSpecialSearchResultsAppend( $specialSearch, $out, $term ) {
		global $wgCirrusSearchFeedbackLink;

		if ( $wgCirrusSearchFeedbackLink ) {
			self::addSearchFeedbackLink( $wgCirrusSearchFeedbackLink, $specialSearch, $out );
		}

		// Embed metrics if this was a Cirrus page
		$engine = $specialSearch->getSearchEngine();
		if ( $engine instanceof CirrusSearch ) {
			$out->addJsConfigVars( $engine->getLastSearchMetrics() );
		}
		return true;
	}

	/**
	 * @param string $link
	 * @param SpecialSearch $specialSearch
	 * @param OutputPage $out
	 */
	private static function addSearchFeedbackLink( $link, SpecialSearch $specialSearch, OutputPage $out ) {
		$anchor = Xml::element(
			'a',
			[ 'href' => $link ],
			$specialSearch->msg( 'cirrussearch-give-feedback' )->text()
		);
		$block = Html::rawElement( 'div', [], $anchor );
		$out->addHTML( $block );
	}

	/**
	 * Hooked to update the search index when pages change directly or when templates that
	 * they include change.
	 * @param LinksUpdate $linksUpdate source of all links update information
	 * @return bool
	 */
	public static function onLinksUpdateCompleted( $linksUpdate ) {
		global $wgCirrusSearchLinkedArticlesToUpdate,
			$wgCirrusSearchUnlinkedArticlesToUpdate,
			$wgCirrusSearchUpdateDelay;

		// Titles that are created by a move don't need their own job.
		if ( in_array( $linksUpdate->getTitle()->getPrefixedDBkey(), self::$movingTitles ) ) {
			return true;
		}

		$params = [
			'addedLinks' => self::prepareTitlesForLinksUpdate(
				$linksUpdate->getAddedLinks(), $wgCirrusSearchLinkedArticlesToUpdate ),
			'removedLinks' => self::prepareTitlesForLinksUpdate(
				$linksUpdate->getRemovedLinks(), $wgCirrusSearchUnlinkedArticlesToUpdate ),
		];
		// Prioritize jobs that are triggered from a web process.  This should prioritize
		// single page update jobs over those triggered by template changes.
		if ( PHP_SAPI != 'cli' ) {
			$params[ 'prioritize' ] = true;
		}
		$job = new Job\LinksUpdate( $linksUpdate->getTitle(), $params );
		$delay = $wgCirrusSearchUpdateDelay[ $job->isPrioritized() ? 'prioritized' : 'default' ];
		$job->setDelay( $delay );

		JobQueueGroup::singleton()->push( $job );
		return true;
	}

	/**
	 * Register Cirrus's unit tests.
	 * @param array &$files containing tests
	 * @return bool
	 */
	public static function onUnitTestsList( &$files ) {
		// This is pretty much exactly how the Translate extension declares its
		// multiple test directories.  There really isn't any excuse for doing
		// it any other way.
		$dir = __DIR__ . '/../tests/phpunit';
		$directoryIterator = new RecursiveDirectoryIterator( $dir );
		$fileIterator = new RecursiveIteratorIterator( $directoryIterator );

		foreach ( $fileIterator as $fileInfo ) {
			if ( substr( $fileInfo->getFilename(), -8 ) === 'Test.php' ) {
				$files[] = $fileInfo->getPathname();
			}
		}

		// a bit of a hack...but pull in abstract classes that arn't in the autoloader
		require_once $dir . '/Query/BaseSimpleKeywordFeatureTest.php';

		return true;
	}

	/**
	 * Extract namespaces from query string.
	 * @param array &$namespaces
	 * @param string &$search
	 * @return bool
	 */
	public static function prefixSearchExtractNamespace( &$namespaces, &$search ) {
		$searcher = new Searcher( self::getConnection(), 0, 1, self::getConfig(), $namespaces );
		$searcher->updateNamespacesFromQuery( $search );
		$namespaces = $searcher->getSearchContext()->getNamespaces();
		return false;
	}

	/**
	 * Let Elasticsearch take a crack at getting near matches once mediawiki has tried all kinds of variants.
	 * @param string $term the original search term and all language variants
	 * @param null|Title &$titleResult resulting match.  A Title if we found something, unchanged otherwise.
	 * @return bool return false if we find something, true otherwise so mediawiki can try its default behavior
	 * @throws ApiUsageException
	 * @throws UsageException
	 */
	public static function onSearchGetNearMatch( $term, &$titleResult ) {
		global $wgContLang;

		$title = Title::newFromText( $term );
		if ( $title === null ) {
			return false;
		}

		$user = RequestContext::getMain()->getUser();
		// Ask for the first 50 results we see.  If there are more than that too bad.
		$searcher = new Searcher( self::getConnection(), 0, 50, self::getConfig(), [ $title->getNamespace() ], $user );
		if ( $title->getNamespace() === NS_MAIN ) {
			$searcher->updateNamespacesFromQuery( $term );
		} else {
			$term = $title->getText();
		}
		$searcher->setResultsType( new FancyTitleResultsType( 'near_match' ) );
		try {
			$status = $searcher->nearMatchTitleSearch( $term );
		} catch ( ApiUsageException $e ) {
			if ( defined( 'MW_API' ) ) {
				throw $e;
			}
			return true;
		} catch ( UsageException $e ) {
			if ( defined( 'MW_API' ) ) {
				throw $e;
			}
			return true;
		}
		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// construction site to log errors.
		if ( !$status->isOK() ) {
			return true;
		}

		$picker = new NearMatchPicker( $wgContLang, $term, $status->getValue() );
		$best = $picker->pickBest();
		if ( $best ) {
			$titleResult = $best;
			return false;
		}
		// Didn't find a result so let Mediawiki have a crack at it.
		return true;
	}

	/**
	 * Before we've moved a title from $title to $newTitle.
	 * @param Title $title old title
	 * @param Title $newTitle new title
	 * @param User $user User who made the move
	 * @return bool should move move actions be precessed (yes)
	 */
	public static function onTitleMove( Title $title, Title $newTitle, $user ) {
		self::$movingTitles[] = $title->getPrefixedDBkey();

		return true;
	}

	/**
	 * When we've moved a Title from A to B.
	 * @param Title $title The old title
	 * @param Title $newTitle The new title
	 * @param User $user User who made the move
	 * @param int $oldId The page id of the old page.
	 * @return bool
	 */
	public static function onTitleMoveComplete( Title $title, Title $newTitle, $user, $oldId ) {
		// When a page is moved the update and delete hooks are good enough to catch
		// almost everything.  The only thing they miss is if a page moves from one
		// index to another.  That only happens if it switches namespace.
		if ( $title->getNamespace() !== $newTitle->getNamespace() ) {
			$conn = self::getConnection();
			$oldIndexType = $conn->getIndexSuffixForNamespace( $title->getNamespace() );
			$job = new Job\DeletePages( $title, [
				'indexType' => $oldIndexType,
				'docId' => self::getConfig()->makeId( $oldId )
			] );
			// Push the job after DB commit but cancel on rollback
			wfGetDB( DB_MASTER )->onTransactionIdle( function () use ( $job ) {
				JobQueueGroup::singleton()->lazyPush( $job );
			} );
		}

		return true;
	}

	/**
	 * Take a list of titles either linked or unlinked and prepare them for Job\LinksUpdate.
	 * This includes limiting them to $max titles.
	 * @param Title[] $titles titles to prepare
	 * @param int $max maximum number of titles to return
	 * @return array
	 */
	private static function prepareTitlesForLinksUpdate( $titles, $max ) {
		$titles = self::pickFromArray( $titles, $max );
		$dBKeys = [];
		foreach ( $titles as $title ) {
			$dBKeys[] = $title->getPrefixedDBkey();
		}
		return $dBKeys;
	}

	/**
	 * Pick $num random entries from $array.
	 * @param array $array Array to pick from
	 * @param int $num Number of entries to pick
	 * @return array of entries from $array
	 */
	private static function pickFromArray( $array, $num ) {
		if ( $num > count( $array ) ) {
			return $array;
		}
		if ( $num < 1 ) {
			return [];
		}
		$chosen = array_rand( $array, $num );
		// If $num === 1 then array_rand will return a key rather than an array of keys.
		if ( !is_array( $chosen ) ) {
			return [ $array[ $chosen ] ];
		}
		$result = [];
		foreach ( $chosen as $key ) {
			$result[] = $array[ $key ];
		}
		return $result;
	}

	/**
	 * ResourceLoaderGetConfigVars hook handler
	 * This should be used for variables which vary with the html
	 * and for variables this should work cross skin
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderGetConfigVars
	 *
	 * @param array &$vars
	 * @return bool
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgCirrusSearchEnableSearchLogging,
			$wgCirrusSearchFeedbackLink;

		$vars += [
			'wgCirrusSearchEnableSearchLogging' => $wgCirrusSearchEnableSearchLogging,
			'wgCirrusSearchFeedbackLink' => $wgCirrusSearchFeedbackLink,
		];

		return true;
	}

	/**
	 * @return SearchConfig
	 */
	private static function getConfig() {
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
	 * @param Title $title
	 * @param mixed &$text
	 * @param mixed $result
	 * @param mixed $terms
	 * @param mixed $page
	 * @param array &$query
	 */
	public static function onShowSearchHitTitle( Title $title, &$text, $result, $terms, $page, &$query = [] ) {
		global $wgCirrusSearchInterwikiProv;
		if ( $wgCirrusSearchInterwikiProv && $title->isExternal() ) {
			$query["wprov"] = $wgCirrusSearchInterwikiProv;
		}
	}

	/**
	 * @param ApiBase $module
	 * @return bool
	 */
	public static function onAPIAfterExecute( $module ) {
		if ( !( $module instanceof ApiOpenSearch ) ) {
			return true;
		}

		$types = ElasticsearchIntermediary::getQueryTypesUsed();
		if ( !$types ) {
			return true;
		}

		$response = $module->getContext()->getRequest()->response();
		$response->header( 'X-OpenSearch-Type: ' . implode( ',', $types ) );
		return true;
	}

	/**
	 * @param string $term
	 * @param SearchResultSet|null $titleMatches
	 * @param SearchResultSet|null $textMatches
	 */
	public static function onSpecialSearchResults( $term, $titleMatches, $textMatches ) {
		global $wgOut,
			$wgCirrusExploreSimilarResults;

		$wgOut->addModules( 'ext.cirrus.serp' );

		if ( $wgCirrusExploreSimilarResults ) {
			$wgOut->addModules( 'ext.cirrus.explore-similar' );
		}

		$wgOut->addJsConfigVars( [
			'wgCirrusSearchRequestSetToken' => Util::getRequestSetToken(),
		] );

		// This ignores interwiki results for now...not sure what do do with those
		ElasticsearchIntermediary::setResultPages( [
			$titleMatches,
			$textMatches
		] );
	}

	public static function onGetPreferences( $user, &$prefs ) {
		$search = new CirrusSearch();
		$profiles = $search->getProfiles( \SearchEngine::COMPLETION_PROFILE_TYPE, $user );
		if ( !empty( $profiles ) && count( $profiles ) > 1 ) {
			$prefs['cirrussearch-pref-completion-profile'] = [
				'class' => HTMLCompletionProfileSettings::class,
				'section' => 'searchoptions/completion',
				'profiles' => $profiles
			];
		}
		return true;
	}

	public static function onUserGetDefaultOptions( &$defaultOptions ) {
		$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		$defaultOptions['cirrussearch-pref-completion-profile'] = $config->get( 'CirrusSearchCompletionSettings' );
		return true;
	}

	/**
	 * Register CirrusSearch services
	 * @param MediaWikiServices $container
	 * @return bool
	 */
	public static function onMediaWikiServices( MediaWikiServices $container ) {
		$container->defineService(
			InterwikiResolverFactory::SERVICE,
			[ InterwikiResolverFactory::class, 'newFactory' ]
		);
		$container->defineService(
			InterwikiResolver::SERVICE,
			function ( MediaWikiServices $serviceContainer ) {
				$config = $serviceContainer->getConfigFactory()
						->makeConfig( 'CirrusSearch' );
				return $serviceContainer
					->getService( InterwikiResolverFactory::SERVICE )
					->getResolver( $config );
			}
		);
		$container->defineService( SearchProfileServiceFactory::SERVICE_NAME,
			function ( MediaWikiServices $serviceContainer ) {
				return new SearchProfileServiceFactory();
			}
		);
	}

	/**
	 * When article is undeleted - check the archive for other instances of the title,
	 * if not there - drop it from the archive.
	 * @param Title $title
	 * @param bool $create
	 * @param string $comment
	 * @param string $oldPageId
	 * @param array $restoredPages
	 * @return bool
	 */
	public static function onArticleUndelete( Title $title, $create, $comment, $oldPageId, $restoredPages ) {
		global $wgCirrusSearchIndexDeletes;
		if ( !$wgCirrusSearchIndexDeletes ) {
			// Not indexing, thus nothing to remove here.
			return true;
		}
		JobQueueGroup::singleton()->push(
			new Job\DeleteArchive( $title, [ 'docIds' => $restoredPages ] )
		);
		return true;
	}

	public static function onSpecialStatsAddExtra( &$extraStats, $context ) {
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

	/**
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param bool $isMinor
	 * @param bool $isWatch
	 * @param string $section
	 * @param int $flags
	 * @param Revision $revision
	 */
	public static function onPageContentInsertComplete( WikiPage $wikiPage, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision ) {
		global $wgCirrusSearchInstantIndexNew;
		if ( empty( $wgCirrusSearchInstantIndexNew ) ) {
			return;
		}
		if ( $wikiPage->isRedirect() ) {
			// Not much point to instant-index redirects since they usually won't have
			// much useful content.
			return;
		}
		if ( is_array( $wgCirrusSearchInstantIndexNew ) ) {
			$namespace = $wikiPage->getTitle()->getNamespace();
			if ( !in_array( $namespace, $wgCirrusSearchInstantIndexNew ) ) {
				// Index only in namespaces specified in the config
				return;
			}
		}
		// Update newly created page. This may not have all the correct link data, etc.
		// but that will be picked up later by the LinkUpdate job.
		DeferredUpdates::addCallableUpdate( function () use ( $wikiPage ) {
			$updater = new Updater( self::getConnection(), self::getConfig() );
			$updater->updatePages( [ $wikiPage ],
				Updater::SKIP_LINKS | Updater::INDEX_ON_SKIP | Updater::INSTANT_INDEX );
		} );
	}

}
