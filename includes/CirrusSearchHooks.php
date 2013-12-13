<?php
/**
 * Simple wrappers around things for hooks to call.
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
			$wgHooks['PrefixSearchBackend'][] = 'CirrusSearch::prefixSearch';
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
}
