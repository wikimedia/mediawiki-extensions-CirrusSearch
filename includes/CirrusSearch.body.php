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

	public function update( $id, $title, $text ) {
		if ( $text === false || $text === null ) { // Can't just check falsy text because empty string is ok!
			wfLogWarning( "Search update called with false or null text for $title.  Ignoring search update." );
			return;
		}
		CirrusSearchUpdater::updateFromTitleAndText( $id, $title, $text );
	}

	public function updateTitle( $id, $title ) {
		$loadedTitle = Title::newFromID( $id );
		if ( $loadedTitle === null ) {
			wfLogWarning( 'Trying to update the search index for a non-existant title.' );
			return;
		}
		CirrusSearchUpdater::updateFromTitle( $loadedTitle );
	}

	public function delete( $id, $title ) {
		CirrusSearchUpdater::deletePages( array( $id ) );
	}

	public function getTextFromContent( Title $t, Content $c = null, $parserOutput = null ) {
		$text = parent::getTextFromContent( $t, $c );
		if( $c ) {
			switch ( $c->getModel() ) {
				case CONTENT_MODEL_WIKITEXT:
					$text = CirrusSearchTextFormatter::formatWikitext( $t, $parserOutput );
					break;
				default:
					$text = SearchUpdate::updateText( $text );
					break;
			}
		}
		return $text;
	}

	public function textAlreadyUpdatedForIndex() {
		return true;
	}

	/**
	 * Noop because Elasticsearch handles all required normalization.
	 * @param string $string String to process
	 * @return string $string exactly as passed in
	 */
	public function normalizeText( $string ) {
		return $string;
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

	public static function softwareInfoHook( $software ) {
		$version = CirrusSearchSearcher::getElasticsearchVersion();
		if ( $version->isOk() ) {
			// We've already logged if this isn't ok and there is no need to warn the user on this page.
			$software[ '[http://www.elasticsearch.org/ Elasticsearch]' ] = $version->getValue();
		}
	}
}
