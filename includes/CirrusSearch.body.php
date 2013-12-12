<?php
/**
 * SearchEngine implementation for CirrusSearch.  Delegates to
 * CirrusSearchSearcher for searches and CirrusSearchUpdater for updates.
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
	const MORE_LIKE_THIS_PREFIX = 'morelike:';

	/**
	 * Override supports to shut off updates to Cirrus via the SearchEngine infrastructure.  Page
	 * updates and additions are chained on the end of the links update job.  Deletes are noticed
	 * via the ArticleDeleteComplete hook.
	 * @param $feature String representing feature
	 * @return bool is this feature supported?
	 */
	public function supports( $feature ) {
		switch ( $feature ) {
		case 'search-update':
			return false;
		default:
			return parent::supports( $feature );
		}
	}

	/**
	 * Overridden to delegate prefix searching to CirrusSearchSearcher.
	 * @param $term string to search
	 * @return CirrusSearchResultSet|null|Status results, no results, or error respectively
	 */
	public function searchText( $term ) {
		$searcher = new CirrusSearchSearcher( $this->offset, $this->limit, $this->namespaces );

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
		}
		// Delegate to either searchText or moreLikeThisArticle and dump the result into $status
		if ( substr( $term, 0, strlen( self::MORE_LIKE_THIS_PREFIX ) ) === self::MORE_LIKE_THIS_PREFIX ) {
			$term = substr( $term, strlen( self::MORE_LIKE_THIS_PREFIX ) );
			$title = Title::newFromText( $term );
			if ( !$title ) {
				$status = Status::newGood( null );
			} else {
				$status = $searcher->moreLikeThisArticle( $title->getArticleID() );
			}
		} else {
			$status = $searcher->searchText( $term, $this->showRedirects );
		}

		// For historical reasons all callers of searchText interpret any Status return as an error
		// so we must unwrap all OK statuses.  Note that $status can be "good" and still contain null
		// since that is interpreted as no results.
		if ( $status->isOK() ) {
			return $status->getValue();
		}
		return $status;
	}

	/**
	 * Hooked to delegate prefix searching to CirrusSearchSearcher.
	 * @param $ns int namespace to search
	 * @param $search string search text
	 * @param $limit int maximum number of titles to return
	 * @param $results array(String) outbound variable with string versions of titles
	 * @return bool always false because we are the authoritative prefix search
	 */
	public static function prefixSearch( $ns, $search, $limit, &$results ) {
		$searcher = new CirrusSearchSearcher( 0, $limit, $ns );
		$searcher->setResultsType( new CirrusSearchTitleResultsType() );
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
	 * Merge the prefix into the query (if any).
	 * @var $term string search term
	 */
	public function transformSearchTerm( $term ) {
		if ( $this->prefix != '' ) {
			// Slap the standard prefix notation onto the query
			$term = $term . ' prefix:' . $this->prefix;
		}
		return $term;
	}

	public static function articleDeleteCompleteHook( $page, $user, $reason, $id ) {
		// Note that we must use the article id provided or it'll be lost in the ether.  The job can't
		// load it from the title because the page row has already been deleted.
		JobQueueGroup::singleton()->push( CirrusSearchDeletePagesJob::build(
			$page->getTitle(), $id ) );
	}


	public static function softwareInfoHook( $software ) {
		$version = CirrusSearchSearcher::getElasticsearchVersion();
		if ( $version->isOk() ) {
			// We've already logged if this isn't ok and there is no need to warn the user on this page.
			$software[ '[http://www.elasticsearch.org/ Elasticsearch]' ] = $version->getValue();
		}
	}

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
		global $wgCirrusSearchEnablePref;

		if ( $wgCirrusSearchEnablePref ) {
			$prefs['cirrussearch-default'] = array(
				'label-message' => 'cirrussearch-pref-label',
				'desc-message' => 'cirrussearch-pref-desc',
				'info-link' => 'https://www.mediawiki.org/wiki/Search',
				'discussion-link' => 'https://www.mediawiki.org/wiki/Talk:Search',
			);
		}

		return true;
	}

	/**
	 * Hooked to call initialize after the user is set up.
	 */
	public static function beforeInitializeHook( $title, $unused, $outputPage, $user, $request, $mediaWiki ) {
		self::initialize( $user );

		return true;
	}

	/**
	 * Hooked to call initialize after the user is set up.
	 */
	public static function apiBeforeMainHook( $apiMain ) {
		self::initialize( RequestContext::getMain()->getUser() );

		return true;
	}

	/**
	 * Initializes the portions of Cirrus that require the $user to be fully initialized and therefore
	 * cannot be done in $wgExtensionFunctions.  Specifically this means the beta features check and
	 * installing the prefix search hook, because it needs information from the beta features check.
	 */
	private static function initialize( $user ) {
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
}
