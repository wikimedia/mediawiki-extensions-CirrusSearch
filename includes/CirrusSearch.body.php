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
	 * @param string $term
	 * @return CirrusSearchResultSet|null|SearchResultSet|Status
	 */
	public function searchText( $term ) {
		$searcher = new CirrusSearchSearcher( $this->offset, $this->limit, $this->namespaces );

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
		}
		if ( substr( $term, 0, strlen( self::MORE_LIKE_THIS_PREFIX ) ) === self::MORE_LIKE_THIS_PREFIX ) {
			$term = substr( $term, strlen( self::MORE_LIKE_THIS_PREFIX ) );
			$title = Title::newFromText( $term );
			if ( !$title ) {
				return null;
			}
			return $searcher->moreLikeThisArticle( $title->getArticleID() );
		}
		return $searcher->searchText( $term, $this->showRedirects );
	}

	/**
	 * @param $ns
	 * @param $search
	 * @param $limit
	 * @param $results
	 * @return bool
	 */
	public static function prefixSearch( $ns, $search, $limit, &$results ) {
		$searcher = new CirrusSearchSearcher( 0, $limit, $ns );
		$searcher->setResultsType( new CirrusSearchTitleResultsType() );
		$results = $searcher->prefixSearch( $search );
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

}
