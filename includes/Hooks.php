<?php

namespace CirrusSearch;

use ApiMain;
use BetaFeatures;
use CirrusSearch;
use CirrusSearch\Search\FancyTitleResultsType;
use CirrusSearch\Search\TitleResultsType;
use ConfigFactory;
use DeferredUpdates;
use JobQueueGroup;
use LinksUpdate;
use OutputPage;
use ResourceLoader;
use Skin;
use SpecialSearch;
use Title;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RequestContext;
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
	 * @var array(string) Destination of titles being moved (the ->getPrefixedDBkey() form).
	 */
	private static $movingTitles = array();

	/**
	 * Hooked to call initialize after the user is set up.
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
	private static function initializeForRequest( $request ) {
		global $wgSearchType, $wgHooks,
			$wgCirrusSearchUseExperimentalHighlighter,
			$wgCirrusSearchPhraseRescoreWindowSize,
			$wgCirrusSearchFunctionRescoreWindowSize,
			$wgCirrusSearchFragmentSize,
			$wgCirrusSearchBoostLinks,
			$wgCirrusSearchAllFields,
			$wgCirrusSearchAllFieldsForRescore,
			$wgCirrusSearchPhraseSlop,
			$wgCirrusSearchLogElasticRequests,
			$wgCirrusSearchLogElasticRequestsSecret,
			$wgCirrusSearchEnableAltLanguage;

		// Install our prefix search hook only if we're enabled.
		if ( $wgSearchType === 'CirrusSearch' ) {
			$wgHooks[ 'PrefixSearchExtractNamespace' ][] = 'CirrusSearch\Hooks::prefixSearchExtractNamespace';
			$wgHooks[ 'PrefixSearchBackend' ][] = 'CirrusSearch\Hooks::prefixSearch';
			$wgHooks[ 'SearchGetNearMatch' ][] = 'CirrusSearch\Hooks::onSearchGetNearMatch';
		}

		self::overrideMoreLikeThisOptionsFromMessage();
		PhraseSuggesterProfiles::overrideOptionsFromMessage();

		if ( $request ) {
			// Engage the experimental highlighter if a url parameter requests it
			if ( !$wgCirrusSearchUseExperimentalHighlighter &&
					$request->getVal( 'cirrusHighlighter' ) === 'experimental' ) {
				$wgCirrusSearchUseExperimentalHighlighter = true;
			}
			self::overrideNumeric( $wgCirrusSearchPhraseRescoreWindowSize, $request, 'cirrusPhraseWinwdow', 10000 );
			self::overrideNumeric( $wgCirrusSearchPhraseSlop[ 'boost' ], $request, 'cirrusPhraseSlop', 10 );
			self::overrideNumeric( $wgCirrusSearchFunctionRescoreWindowSize, $request, 'cirrusFunctionWindow', 10000 );
			self::overrideNumeric( $wgCirrusSearchFragmentSize, $request, 'cirrusFragmentSize', 1000 );
			self::overrideYesNo( $wgCirrusSearchBoostLinks, $request, 'cirrusBoostLinks' );
			self::overrideYesNo( $wgCirrusSearchAllFields[ 'use' ], $request, 'cirrusUseAllFields' );
			self::overrideYesNo( $wgCirrusSearchAllFieldsForRescore, $request, 'cirrusUseAllFieldsForRescore' );
			self::overrideUseExtraPluginForRegex( $request );
			self::overrideMoreLikeThisOptions( $request );
			PhraseSuggesterProfiles::overrideOptions( $request );
			CommonTermsQueryProfiles::overrideOptions( $request );
			self::overrideSecret( $wgCirrusSearchLogElasticRequests, $wgCirrusSearchLogElasticRequestsSecret, $request, 'cirrusLogElasticRequests', false );
			self::overrideYesNo( $wgCirrusSearchEnableAltLanguage, $request, 'cirrusAltLanguage' );
		}
	}

	/**
	 * Set $dest to the numeric value from $request->getVal( $name ) if it is <= $limit
	 * or => $limit if upperLimit is false.
	 */
	private static function overrideNumeric( &$dest, $request, $name, $limit = null, $upperLimit = true ) {
		Util::overrideNumeric( $dest, $request, $name, $limit, $upperLimit );
	}

	/**
	 * Set $dest to $value when $request->getVal( $name ) contains $secret
	 */
	private static function overrideSecret( &$dest, $secret, $request, $name, $value = true ) {
		if ( $secret && $secret === $request->getVal( $name ) ) {
			$dest = $value;
		}
	}

	/**
	 * Set $dest to the true/false from $request->getVal( $name ) if yes/no.
	 */
	private static function overrideYesNo( &$dest, $request, $name ) {
		Util::overrideYesNo( $dest, $request, $name );
	}

	private static function overrideUseExtraPluginForRegex( $request ) {
		global $wgCirrusSearchWikimediaExtraPlugin;

		$val = $request->getVal( 'cirrusAccelerateRegex' );
		if ( $val !== null ) {
			if ( $val === 'yes' ) {
				$wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][] = 'use';
			} elseif( $val = 'no' ) {
				if ( isset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] ) ) {
					$useLocation = array_search( 'use', $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] );
					if ( $useLocation !== false ) {
						unset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][ $useLocation ] );
					}
				}
			}
		}
	}

	/**
	 * Extract more like this settings from the i18n message cirrussearch-morelikethis-settings
	 */
	private static function overrideMoreLikeThisOptionsFromMessage() {
		global $wgCirrusSearchMoreLikeThisConfig,
			$wgCirrusSearchMoreLikeThisUseFields,
			$wgCirrusSearchMoreLikeThisAllowedFields,
			$wgCirrusSearchMoreLikeThisMaxQueryTermsLimit,
			$wgCirrusSearchMoreLikeThisFields;

		$source = wfMessage( 'cirrussearch-morelikethis-settings' )->inContentLanguage();
		if ( $source && $source->isDisabled() ) {
			return;
		}
		$lines = Util::parseSettingsInMessage( $source->plain() );

		foreach ( $lines as $line ) {
			list( $k, $v ) = explode( ':', $line, 2 );
			switch( $k ) {
			case 'min_doc_freq':
			case 'max_doc_freq':
			case 'max_query_terms':
			case 'min_term_freq':
			case 'min_word_len':
			case 'max_word_len':
				if( is_numeric( $v ) && $v >= 0 ) {
					$wgCirrusSearchMoreLikeThisConfig[$k] = intval( $v );
				} else if ( $v === 'null' ) {
					unset( $wgCirrusSearchMoreLikeThisConfig[$k] );
				}
				break;
			case 'percent_terms_to_match':
				if( is_numeric( $v ) && $v > 0 && $v <= 1 ) {
					$wgCirrusSearchMoreLikeThisConfig[$k] = $v;
				} else if ($v === 'null' ) {
					unset( $wgCirrusSearchMoreLikeThisConfig[$k] );
				}
				break;
			case 'fields':
				$wgCirrusSearchMoreLikeThisFields = array_intersect(
					array_map( 'trim', explode( ',', $v ) ),
					$wgCirrusSearchMoreLikeThisAllowedFields );
				break;
			case 'use_fields':
				if ( $v === 'true' ) {
					$wgCirrusSearchMoreLikeThisUseFields = true;
				} else if ( $v === 'false' ) {
					$wgCirrusSearchMoreLikeThisUseFields = false;
				}
				break;
			}
			if ( $wgCirrusSearchMoreLikeThisConfig['max_query_terms'] > $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit ) {
				$wgCirrusSearchMoreLikeThisConfig['max_query_terms'] = $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit;
			}
		}
	}

	/**
	 * Override more like this settings from request URI parameters
	 */
	private static function overrideMoreLikeThisOptions( $request ) {
		global $wgCirrusSearchMoreLikeThisConfig,
			$wgCirrusSearchMoreLikeThisUseFields,
			$wgCirrusSearchMoreLikeThisAllowedFields,
			$wgCirrusSearchMoreLikeThisMaxQueryTermsLimit,
			$wgCirrusSearchMoreLikeThisFields;

		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_doc_freq'], $request, 'cirrusMltMinDocFreq' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_doc_freq'], $request, 'cirrusMltMaxDocFreq' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_query_terms'],
			$request, 'cirrusMltMaxQueryTerms', $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_term_freq'], $request, 'cirrusMltMinTermFreq' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['percent_terms_to_match'], $request, 'cirrusMltPercentTermsToMatch', 1 );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['min_word_len'], $request, 'cirrusMltMinWordLength' );
		self::overrideNumeric( $wgCirrusSearchMoreLikeThisConfig['max_word_len'], $request, 'cirrusMltMaxWordLength' );
		self::overrideYesNo( $wgCirrusSearchMoreLikeThisUseFields, $request, 'cirrusMltUseFields' );
		$fields = $request->getVal( 'cirrusMltFields' );
		if( isset( $fields ) ) {
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
		//
		// If we abort or fail deletion it's no big deal because this will
		// end up being a no-op when it executes.
		$target = $page->getRedirectTarget();
		if ( $target ) {
			// DeferredUpdate so we don't end up racing our own page deletion
			DeferredUpdates::addCallableUpdate( function() use ( $target ) {
				JobQueueGroup::singleton()->push(
					new Job\LinksUpdate( $target, array(
						'addedLinks' => array(),
						'removedLinks' => array(),
					) )
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
			new Job\DeletePages( $page->getTitle(), array( 'id' => $pageId ) )
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
			return;
		}
		JobQueueGroup::singleton()->push(
			Job\MassIndex::build(
				array( WikiPage::factory( $title ) ),
				false,
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
			new Job\LinksUpdate( $title, array(
				'addedLinks' => array(),
				'removedLinks' => array(),
				'prioritize' => true
			) )
		);
		return true;
	}

	/**
	 * Hook called to include Elasticsearch version info on Special:Version
	 * @param array $software Array of wikitext and version numbers
	 * @return bool
	 */
	public static function onSoftwareInfo( &$software ) {
		$version = new Version( self::getConnection() );
		$status = $version->get();
		if ( $status->isOk() ) {
			// We've already logged if this isn't ok and there is no need to warn the user on this page.
			$software[ '[https://www.elastic.co/products/elasticsearch Elasticsearch]' ] = $status->getValue();
		}
		return true;
	}

	/**
	 * Called to prepend text before search results and inject metrics
	 * @param SpecialSearch $specialSearch The SpecialPage object for Special:Search
	 * @param OutputPage $out The output page object
	 * @param string $term The term being searched for
	 * @return bool
	 */
	public static function onSpecialSearchResultsPrepend( $specialSearch, $out, $term ) {
		global $wgCirrusSearchShowNowUsing;

		// Prepend our message if needed
		if ( $wgCirrusSearchShowNowUsing ) {
			$out->addHtml( Xml::openElement( 'div', array( 'class' => 'cirrussearch-now-using' ) ) .
				$specialSearch->msg( 'cirrussearch-now-using' )->parse() .
				Xml::closeElement( 'div' ) );
		}

		// Embed metrics if this was a Cirrus page
		$engine = $specialSearch->getSearchEngine();
		if ( $engine instanceof CirrusSearch ) {
			$out->addJsConfigVars( $engine->getLastSearchMetrics() );
		}

		return true;
	}

	public static function onSpecialSearchResultsAppend( $specialSearch, $out ) {
		global $wgCirrusSearchFeedbackLink;
		if ( $wgCirrusSearchFeedbackLink ) {
			self::addSearchFeedbackLink( $wgCirrusSearchFeedbackLink, $specialSearch, $out );
		}
		return true;
	}

	private static function addSearchFeedbackLink( $link, $specialSearch, $out ) {
		$anchor = Xml::element(
			'a',
			array( 'href' => $link ),
			$specialSearch->msg( 'cirrussearch-give-feedback' )->text()
		);
		$block = Html::rawElement( 'div', array(), $anchor );
		$out->addHtml( $block );
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

		$params = array(
			'addedLinks' => self::prepareTitlesForLinksUpdate(
				$linksUpdate->getAddedLinks(), $wgCirrusSearchLinkedArticlesToUpdate ),
			'removedLinks' => self::prepareTitlesForLinksUpdate(
				$linksUpdate->getRemovedLinks(), $wgCirrusSearchUnlinkedArticlesToUpdate ),
		);
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
	 * @param array $files containing tests
	 * @return bool
	 */
	public static function onUnitTestsList( &$files ) {
		// This is pretty much exactly how the Translate extension declares its
		// multiple test directories.  There really isn't any excuse for doing
		// it any other way.
		$dir = __DIR__ . '/../tests/unit';
		$directoryIterator = new RecursiveDirectoryIterator( $dir );
		$fileIterator = new RecursiveIteratorIterator( $directoryIterator );

		foreach ( $fileIterator as $fileInfo ) {
			if ( substr( $fileInfo->getFilename(), -8 ) === 'Test.php' ) {
				$files[] = $fileInfo->getPathname();
			}
		}

		return true;
	}

	public static function prefixSearchExtractNamespace( &$namespaces, &$search ) {
		$user = RequestContext::getMain()->getUser();
		$searcher = new Searcher( self::getConnection(), 0, 1, null, $namespaces, $user );
		$searcher->updateNamespacesFromQuery( $search );
		$namespaces = $searcher->getNamespaces();
		return false;
	}

	/**
	 * Hooked to delegate prefix searching to Searcher.
	 * @param int[] $namespaces namespace to search
	 * @param string $search search text
	 * @param int $limit maximum number of titles to return
	 * @param array(string) $results outbound variable with string versions of titles
	 * @param int $offset Number of results to offset
	 * @return bool always false because we are the authoritative prefix search
	 */
	public static function prefixSearch( $namespaces, $search, $limit, &$results, $offset = 0 ) {
		$user = RequestContext::getMain()->getUser();
		$searcher = new Searcher( self::getConnection(), $offset, $limit, null, $namespaces, $user );
		if ( $search ) {
			$searcher->setResultsType( new FancyTitleResultsType( 'prefix' ) );
		} else {
			// Empty searches always find the title.
			$searcher->setResultsType( new TitleResultsType() );
		}
		try {
			$status = $searcher->prefixSearch( $search );
		} catch ( UsageException $e ) {
			if ( defined( 'MW_API' ) ) {
				throw $e;
			}
			return false;
		}
		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// constrution site to log errors.
		if ( $status->isOK() ) {
			if ( !$search ) {
				// No need to unpack the simple title matches from non-fancy TitleResultsType
				return $status->getValue();
			}
			$results = array();
			foreach ( $status->getValue() as $match ) {
				if ( isset( $match[ 'titleMatch' ] ) ) {
					$results[] = $match[ 'titleMatch' ]->getPrefixedText();
				} else {
					if ( isset( $match[ 'redirectMatches' ][ 0 ] ) ) {
						// TODO maybe dig around in the redirect matches and find the best one?
						$results[] = $match[ 'redirectMatches' ][ 0 ]->getPrefixedText();
					}
				}
			}
		}
		return false;
	}

	/**
	 * Let Elasticsearch take a crack at getting near matches once mediawiki has tried all kinds of variants.
	 * @param string $term the original search term and all language variants
	 * @param null|Title $titleResult resulting match.  A Title if we found something, unchanged otherwise.
	 * @return bool return false if we find something, true otherwise so mediawiki can try its default behavior
	 */
	public static function onSearchGetNearMatch( $term, &$titleResult ) {
		global $wgContLang;

		$title = Title::newFromText( $term );
		if ( $title === null ) {
			return false;
		}

		$user = RequestContext::getMain()->getUser();
		// Ask for the first 50 results we see.  If there are more than that too bad.
		$searcher = new Searcher( self::getConnection(), 0, 50, null, array( $title->getNamespace() ), $user );
		if ( $title->getNamespace() === NS_MAIN ) {
			$searcher->updateNamespacesFromQuery( $term );
		} else {
			$term = $title->getText();
		}
		$searcher->setResultsType( new FancyTitleResultsType( 'near_match' ) );
		try {
			$status = $searcher->nearMatchTitleSearch( $term );
		} catch ( UsageException $e ) {
			if ( defined( 'MW_API' ) ) {
				throw $e;
			}
			return true;
		}
		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// constrution site to log errors.
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
	public static function onTitleMoveComplete( Title &$title, Title &$newTitle, &$user, $oldId ) {
		// When a page is moved the update and delete hooks are good enough to catch
		// almost everything.  The only thing they miss is if a page moves from one
		// index to another.  That only happens if it switches namespace.
		if ( $title->getNamespace() !== $newTitle->getNamespace() ) {
			$conn = self::getConnection();
			$oldIndexType = $conn->getIndexSuffixForNamespace( $title->getNamespace() );
			JobQueueGroup::singleton()->push( new Job\DeletePages( $title, array(
				'indexType' => $oldIndexType,
				'id' => $oldId
			) ) );
		}

		return true;
	}

	/**
	 * Take a list of titles either linked or unlinked and prepare them for Job\LinksUpdate.
	 * This includes limiting them to $max titles.
	 * @param array(Title) $titles titles to prepare
	 * @param int $max maximum number of titles to return
	 * @return array
	 */
	private static function prepareTitlesForLinksUpdate( $titles, $max ) {
		$titles = self::pickFromArray( $titles, $max );
		$dBKeys = array();
		/**
		 * @var Title $title
		 */
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
			return array();
		}
		$chosen = array_rand( $array, $num );
		// If $num === 1 then array_rand will return a key rather than an array of keys.
		if ( !is_array( $chosen ) ) {
			return array( $array[ $chosen ] );
		}
		$result = array();
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
	 * @param array $vars
	 * @return bool
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgCirrusSearchEnableSearchLogging, $wgCirrusSearchFeedbackLink;

		$vars += array(
			'wgCirrusSearchEnableSearchLogging' => $wgCirrusSearchEnableSearchLogging,
			'wgCirrusSearchFeedbackLink' => $wgCirrusSearchFeedbackLink,
		);

		return true;
	}

	private static function getConnection() {
		$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		return new Connection( $config );
	}
}
