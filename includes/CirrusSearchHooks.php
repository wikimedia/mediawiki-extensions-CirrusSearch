<?php
/**
 * All CirrusSearch's hooks.
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
class CirrusSearchHooks {
	/**
	 * Hooked to call initialize after the user is set up.
	 * @return bool
	 */
	public static function beforeInitializeHook( $title, $unused, $outputPage, $user, $request, $mediaWiki ) {
		self::initializeForUser( $user );
		return true;
	}

	/**
	 * Hooked to call initialize after the user is set up.
	 * @param ApiMain $apiMain The ApiMain instance being used
	 * @return bool
	 */
	public static function apiBeforeMainHook( $apiMain ) {
		self::initializeForUser( RequestContext::getMain()->getUser() );
		return true;
	}

	/**
	 * Initializes the portions of Cirrus that require the $user to be fully initialized and therefore
	 * cannot be done in $wgExtensionFunctions.  Specifically this means the beta features check and
	 * installing the prefix search hook, because it needs information from the beta features check.
	 */
	private static function initializeForUser( $user ) {
		global $wgCirrusSearchEnablePref;
		global $wgSearchType;
		global $wgHooks;

		// If the user has the BetaFeature enabled, use Cirrus as default.
		if ( $wgCirrusSearchEnablePref && $user->isLoggedIn() && class_exists( 'BetaFeatures' )
			&& BetaFeatures::isFeatureEnabled( $user, 'cirrussearch-default' )
		) {
			$wgSearchType = 'CirrusSearch';
		}

		// Install our prefix search hook only if we're enabled.
		if ( $wgSearchType === 'CirrusSearch' ) {
			$wgHooks[ 'PrefixSearchBackend' ][] = 'CirrusSearchHooks::prefixSearch';
			$wgHooks[ 'SearchGetNearMatchBefore' ][] = 'CirrusSearchHooks::searchGetNearMatchBeforeHook';
		}
	}

	/**
	 * Hook to call when an article is deleted
	 * @param WikiPage $page The page we're deleting
	 * @param User $user The user deleting the page
	 * @param string $reason Reason the page is being deleted
	 * @param int $id Page id being deleted
	 * @return bool
	 */
	public static function articleDeleteCompleteHook( $page, $user, $reason, $id ) {
		// Note that we must use the article id provided or it'll be lost in the ether.  The job can't
		// load it from the title because the page row has already been deleted.
		JobQueueGroup::singleton()->push( CirrusSearchDeletePagesJob::build(
			$page->getTitle(), $id ) );
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
		$page = WikiPage::factory( $title );
		JobQueueGroup::singleton()->push(
			CirrusSearchUpdatePagesJob::build( array( $page ), true, CirrusSearchUpdater::INDEX_EVERYTHING )
		);
	}

	/**
	 * Hook called to include Elasticsearch version info on Special:Version
	 * @param array $software Array of wikitext and version numbers
	 * @return bool
	 */
	public static function softwareInfoHook( $software ) {
		$version = CirrusSearchSearcher::getElasticsearchVersion();
		if ( $version->isOk() ) {
			// We've already logged if this isn't ok and there is no need to warn the user on this page.
			$software[ '[http://www.elasticsearch.org/ Elasticsearch]' ] = $version->getValue();
		}
		return true;
	}

	/**
	 * Called to prepend text before search results
	 * @param SpecialSearch $specialSearch The SpecialPage object for Special:Search
	 * @param OutputPage $out The output page object
	 * @param string $term The term being searched for
	 * @return bool
	 */
	public static function specialSearchResultsPrependHook( $specialSearch, $out, $term ) {
		global $wgCirrusSearchShowNowUsing;
		if ( $wgCirrusSearchShowNowUsing ) {
			$out->addHtml( Xml::openElement( 'div', array( 'class' => 'cirrussearch-now-using' ) ) .
				$specialSearch->msg( 'cirrussearch-now-using' )->parse() .
				Xml::closeElement( 'div' ) );
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
				'screenshot' => "$wgExtensionAssetsPath/CirrusSearch/cirrus-beta.svg",
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
		$job = new CirrusSearchLinksUpdateJob( $linksUpdate->getTitle(), array(
			'addedLinks' => $linksUpdate->getAddedLinks(),
			'removedLinks' => $linksUpdate->getRemovedLinks(),
			'primary' => true,
		) );
		JobQueueGroup::singleton()->push( $job );
		return true;
	}

	/**
	 * Register Cirrus's unit tests.
	 * @param array $files containing tests
	 * @return bool
	 */
	public static function getUnitTestsList( &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/tests/unit/*Test.php' ) );
		return true;
	}

	/**
	 * Hooked to delegate prefix searching to CirrusSearchSearcher.
	 * @param int $ns namespace to search
	 * @param string $search search text
	 * @param int $limit maximum number of titles to return
	 * @param array(string) $results outbound variable with string versions of titles
	 * @return bool always false because we are the authoritative prefix search
	 */
	public static function prefixSearch( $ns, $search, $limit, &$results ) {
		$searcher = new CirrusSearchSearcher( 0, $limit, $ns );
		$searcher->setResultsType( new CirrusSearchTitleResultsType( true ) );
		$status = $searcher->prefixSearch( $search );
		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// constrution site to log errors.
		if ( $status->isOK() ) {
			$array = $status->getValue();
			$results = $array;
		}
		return false;
	}

	/**
	 * Let Elasticsearch take a crack at getting near matches before mediawiki tries all kinds of variants.
	 * @param array(string) $termAnAllLanguageVariants the original search term and all language variants
	 * @param null|Title $titleResult resulting match.  A Title if we found something, unchanged otherwise.
	 * @return bool return false if we find something, true otherwise so mediawiki can try its default behavior
	 */
	public static function searchGetNearMatchBeforeHook( $termAndAllLanguageVariants, $titleResult ) {
		// Elasticsearch should handle all language variants.  If it doesn't, we'll have to make it do so.
		$term = $termAndAllLanguageVariants[ 0 ];
		$title = Title::newFromText( $term );
		if ( $title === null ) {
			return false;
		}

		$searcher = new CirrusSearchSearcher( 0, 1, array( $title->getNamespace() ) );
		$searcher->setResultsType( new CirrusSearchTitleResultsType( false ) );
		$status = $searcher->nearMatchTitleSearch( $term );
		// There is no way to send errors or warnings back to the caller here so we have to make do with
		// only sending results back if there are results and relying on the logging done at the status
		// constrution site to log errors.
		if ( !$status->isOK() ) {
			return true;
		}
		$array = $status->getValue();
		if ( !isset( $array[ 0 ] ) ) {
			return true;
		}
		$titleResult = $array[ 0 ];
		return false;
	}
}
