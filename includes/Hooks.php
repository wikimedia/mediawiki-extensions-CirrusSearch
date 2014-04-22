<?php

namespace CirrusSearch;
use \CirrusSearch;
use \BetaFeatures;
use \JobQueueGroup;
use \Title;
use \RequestContext;
use \WikiPage;
use \Xml;

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
	 * Hooked to call initialize after the user is set up.
	 * @return bool
	 */
	public static function beforeInitializeHook( $title, $unused, $outputPage, $user, $request, $mediaWiki ) {
		self::initializeForUser( $user, $request );
		return true;
	}

	/**
	 * Hooked to call initialize after the user is set up.
	 * @param ApiMain $apiMain The ApiMain instance being used
	 * @return bool
	 */
	public static function apiBeforeMainHook( $apiMain ) {
		self::initializeForUser( $apiMain->getUser(), $apiMain->getRequest() );
		return true;
	}

	/**
	 * Initializes the portions of Cirrus that require the $user to be fully initialized and therefore
	 * cannot be done in $wgExtensionFunctions.  Specifically this means the beta features check and
	 * installing the prefix search hook, because it needs information from the beta features check.
	 */
	private static function initializeForUser( $user, $request ) {
		global $wgCirrusSearchEnablePref;
		global $wgSearchType;
		global $wgSearchTypeAlternatives;
		global $wgHooks;
		global $wgCirrusSearchUseExperimentalHighlighter;

		// If the user has the BetaFeature enabled, use Cirrus as default.
		if ( $wgCirrusSearchEnablePref && $user->isLoggedIn() && class_exists( 'BetaFeatures' )
			&& BetaFeatures::isFeatureEnabled( $user, 'cirrussearch-default' )
		) {
			// Make the old main search available as an alternative (bug 60439)
			$wgSearchTypeAlternatives[] = $wgSearchType;
			$wgSearchType = 'CirrusSearch';
		}

		// Install our prefix search hook only if we're enabled.
		if ( $wgSearchType === 'CirrusSearch' ) {
			$wgHooks[ 'PrefixSearchBackend' ][] = 'CirrusSearch\Hooks::prefixSearch';
			$wgHooks[ 'SearchGetNearMatchBefore' ][] = 'CirrusSearch\Hooks::searchGetNearMatchBeforeHook';
		}

		// Engage the experimental highlighter if a url parameter requests it
		if ( !$wgCirrusSearchUseExperimentalHighlighter && $request &&
				$request->getVal( 'cirrusHighlighter' ) === 'experimental' ) {
			$wgCirrusSearchUseExperimentalHighlighter = true;
		}
	}

	/**
	 * Hook to call when an article is deleted
	 * @param WikiPage $page The page we're deleting
	 * @param User $user The user deleting the page
	 * @param string $reason Reason the page is being deleted
	 * @param int $pageId Page id being deleted
	 * @return bool
	 */
	public static function articleDeleteCompleteHook( $page, $user, $reason, $pageId ) {
		// Note that we must use the article id provided or it'll be lost in the ether.  The job can't
		// load it from the title because the page row has already been deleted.
		JobQueueGroup::singleton()->push(
			new DeletePagesJob( $page->getTitle(), array( 'id' => $pageId ) )
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
		JobQueueGroup::singleton()->push(
			MassIndexJob::build(
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
			new LinksUpdateJob( $title, array(
				'addedLinks' => array(),
				'removedLinks' => array()
			) )
		);
		return true;
	}

	/**
	 * Hook called to include Elasticsearch version info on Special:Version
	 * @param array $software Array of wikitext and version numbers
	 * @return bool
	 */
	public static function softwareInfoHook( $software ) {
		$searcher = new Searcher( 0, 0, array(), false );
		$version = $searcher->getElasticsearchVersion();
		if ( $version->isOk() ) {
			// We've already logged if this isn't ok and there is no need to warn the user on this page.
			$software[ '[http://www.elasticsearch.org/ Elasticsearch]' ] = $version->getValue();
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
	public static function specialSearchResultsPrependHook( $specialSearch, $out, $term ) {
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

	/**
	 * Adds using CirrusSearch as default as a BetaFeature
	 * @param User $user
	 * @param array $prefs
	 * @return bool
	 */
	public static function getPreferencesHook( $user, &$prefs ) {
		global $wgCirrusSearchEnablePref, $wgExtensionAssetsPath;

		if ( $wgCirrusSearchEnablePref ) {
			$prefs['cirrussearch-default'] = array(
				'label-message' => 'cirrussearch-pref-label',
				'desc-message' => 'cirrussearch-pref-desc',
				'info-link' => 'https://www.mediawiki.org/wiki/Search',
				'discussion-link' => 'https://www.mediawiki.org/wiki/Talk:Search',
				'screenshot' => array(
					'ltr' => "$wgExtensionAssetsPath/CirrusSearch/cirrus-beta-ltr.svg",
					'rtl' => "$wgExtensionAssetsPath/CirrusSearch/cirrus-beta-rtl.svg",
				),
			);
		}

		return true;
	}

	/**
	 * Hooked to update the search index when pages change directly or when templates that
	 * they include change.
	 * @param $linksUpdate LinksUpdate source of all links update information
	 * @return bool
	 */
	public static function linksUpdateCompletedHook( $linksUpdate ) {
		global $wgCirrusSearchLinkedArticlesToUpdate;
		global $wgCirrusSearchUnlinkedArticlesToUpdate;

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
		$job = new LinksUpdateJob( $linksUpdate->getTitle(), $params );
		JobQueueGroup::singleton()->push( $job );
		return true;
	}

	/**
	 * Register Cirrus's unit tests.
	 * @param array $files containing tests
	 * @return bool
	 */
	public static function getUnitTestsList( &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/../tests/unit/*Test.php' ) );
		return true;
	}

	/**
	 * Hooked to delegate prefix searching to Searcher.
	 * @param int $namespace namespace to search
	 * @param string $search search text
	 * @param int $limit maximum number of titles to return
	 * @param array(string) $results outbound variable with string versions of titles
	 * @return bool always false because we are the authoritative prefix search
	 */
	public static function prefixSearch( $namespace, $search, $limit, &$results ) {
		$user = RequestContext::getMain()->getUser();
		$searcher = new Searcher( 0, $limit, $namespace, $user );
		$searcher->setResultsType( new TitleResultsType( 'prefix' ) );
		$status = $searcher->prefixSearch( $search );
		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// constrution site to log errors.
		if ( $status->isOK() ) {
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
	 * Let Elasticsearch take a crack at getting near matches before mediawiki tries all kinds of variants.
	 * @param array(string) $termAnAllLanguageVariants the original search term and all language variants
	 * @param null|Title $titleResult resulting match.  A Title if we found something, unchanged otherwise.
	 * @return bool return false if we find something, true otherwise so mediawiki can try its default behavior
	 */
	public static function searchGetNearMatchBeforeHook( $termAndAllLanguageVariants, &$titleResult ) {
		global $wgContLang;

		// Elasticsearch should handle all language variants.  If it doesn't, we'll have to make it do so.
		$term = $termAndAllLanguageVariants[ 0 ];
		$title = Title::newFromText( $term );
		if ( $title === null ) {
			return false;
		}

		$user = RequestContext::getMain()->getUser();
		// Ask for the first 50 results we see.  If there are more than that too bad.
		$searcher = new Searcher( 0, 50, array( $title->getNamespace() ), $user );
		$searcher->setResultsType( new TitleResultsType( 'near_match' ) );
		$status = $searcher->nearMatchTitleSearch( $term );
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
	 * Take a list of titles either linked or unlinked and prepare them for LinksUpdateJob.
	 * This includes limiting them to $max titles.
	 * @param array(Title) $titles titles to prepare
	 * @param int $max maximum number of titles to return
	 */
	private static function prepareTitlesForLinksUpdate( $titles, $max ) {
		$titles = self::pickFromArray( $titles, $max );
		$dBKeys = array();
		foreach ( $titles as $title ) {
			$dBKeys[] = $title->getPrefixedDBkey();
		}
		return $dBKeys;
	}

	/**
	 * Pick $num random entries from $array.
	 * @var $array array array to pick from
	 * @var $num int number of entries to pick
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
}
