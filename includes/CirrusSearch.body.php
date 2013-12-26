<?php
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
	const MORE_LIKE_THIS_PREFIX = 'morelike:';

	/**
	 * @var string The last prefix substituted by replacePrefixes.
	 */
	private $lastNamespacePrefix;

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
			return false;
		default:
			return parent::supports( $feature );
		}
	}

	/**
	 * Overridden to delegate prefix searching to CirrusSearchSearcher.
	 * @param string $term text to search
	 * @return CirrusSearchResultSet|null|Status results, no results, or error respectively
	 */
	public function searchText( $term ) {
		$searcher = new CirrusSearchSearcher( $this->offset, $this->limit, $this->namespaces );

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
			$searcher->addSuggestPrefix( '~' );
		}

		if ( $this->lastNamespacePrefix ) {
			$searcher->addSuggestPrefix( $this->lastNamespacePrefix );
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
	 * Merge the prefix into the query (if any).
	 * @var string $term search term
	 * @return string possibly with a prefix appended
	 */
	public function transformSearchTerm( $term ) {
		if ( $this->prefix != '' ) {
			// Slap the standard prefix notation onto the query
			$term = $term . ' prefix:' . $this->prefix;
		}
		return $term;
	}

	public function replacePrefixes( $query ) {
		$parsed = parent::replacePrefixes( $query );
		if ( $parsed !== $query ) {
			$this->lastNamespacePrefix = substr( $query, 0, strlen( $query ) - strlen( $parsed ) );
		} else {
			$this->lastNamespacePrefix = '';
		}
		return $parsed;
	}
}
