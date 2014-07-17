<?php

use CirrusSearch\InterwikiSearcher;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Searcher;

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
	 * @var array metrics about the last thing we searched
	 */
	private $lastSearchMetrics;

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
		case 'list-redirects':
			return false;
		default:
			return parent::supports( $feature );
		}
	}

	/**
	 * Overridden to delegate prefix searching to Searcher.
	 * @param string $term text to search
	 * @return Search\ResultSet|null|Status results, no results, or error respectively
	 */
	public function searchText( $term ) {
		global $wgCirrusSearchInterwikiSources;

		$term = trim( $term );
		// No searching for nothing!  That takes forever!
		if ( !$term ) {
			return null;
		}

		$context = RequestContext::getMain();
		$user = $context->getUser();
		$searcher = new Searcher( $this->offset, $this->limit, $this->namespaces, $user );

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
			$searcher->addSuggestPrefix( '~' );
		}

		if ( $this->lastNamespacePrefix ) {
			$searcher->addSuggestPrefix( $this->lastNamespacePrefix );
		}
		// TODO remove this when we no longer have to support core versions without
		// Ie946150c6796139201221dfa6f7750c210e97166
		if ( method_exists( $this, 'getSort' ) ) {
			$searcher->setSort( $this->getSort() );
		}

		// Delegate to either searchText or moreLikeThisArticle and dump the result into $status
		if ( substr( $term, 0, strlen( self::MORE_LIKE_THIS_PREFIX ) ) === self::MORE_LIKE_THIS_PREFIX ) {
			$term = substr( $term, strlen( self::MORE_LIKE_THIS_PREFIX ) );
			$titles = array();
			foreach ( explode( '|', $term ) as $title ) {
				$title = Title::newFromText( trim( $title ) );
				if ( $title ) {
					$titles[] = $title;
				}
			}
			if ( count( $titles ) ) {
				$status = $searcher->moreLikeTheseArticles( $titles );
			} else {
				$status = Status::newGood( null );
			}
		} else {
			$request = $context->getRequest();
			$highlightingConfig = FullTextResultsType::HIGHLIGHT_ALL;
			if ( $request ) {
				if ( $request->getVal( 'cirrusSuppressSuggest' ) !== null ) {
					$this->showSuggestion = false;
				}
				if ( $request->getVal( 'cirrusSuppressTitleHighlight' ) !== null ) {
					$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_TITLE;
				}
				if ( $request->getVal( 'cirrusSuppressAltTitle' ) !== null ) {
					$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_ALT_TITLE;
				}
				if ( $request->getVal( 'cirrusSuppressSnippet' ) !== null ) {
					$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_SNIPPET;
				}
				if ( $request->getVal( 'cirrusHighlightDefaultSimilarity' ) === 'false' ) {
					$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_WITH_DEFAULT_SIMILARITY;
				}
			}
			if ( $this->namespaces && !in_array( NS_FILE, $this->namespaces ) ) {
				$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_FILE_TEXT;
			}
			$searcher->setResultsType( new FullTextResultsType( $highlightingConfig ) );
			$status = $searcher->searchText( $term, $this->showSuggestion );
		}

		$this->lastSearchMetrics = $searcher->getSearchMetrics();

		// Add interwiki results, if we have a sane result
		// Note that we have no way of sending warning back to the user.  In this case all warnings
		// are logged when they are added to the status object so we just ignore them here....
		if ( $status->isOK() && $wgCirrusSearchInterwikiSources ) {
			// @todo @fixme: This should absolutely be a multisearch. I knew this when I
			// wrote the code but Searcher needs some refactoring first.
			foreach ( $wgCirrusSearchInterwikiSources as $interwiki => $index ) {
				$iwSearch = new InterwikiSearcher( $this->namespaces, $user, $index, $interwiki );
				$interwikiResult = $iwSearch->getInterwikiResults( $term );
				if ( $interwikiResult ) {
					$status->getValue()->addInterwikiResults( $interwikiResult );
				}
			}
		}

		// For historical reasons all callers of searchText interpret any Status return as an error
		// so we must unwrap all OK statuses.  Note that $status can be "good" and still contain null
		// since that is interpreted as no results.
		return $status->isOk() ? $status->getValue() : $status;
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

	/**
	 * Get the sort of sorts we allow
	 * @return array
	 */
	public function getValidSorts() {
		return array( 'relevance', 'title_asc', 'title_desc', 'random' );
	}

	/**
	 * Get the metrics for the last search we performed. Null if we haven't done any.
	 * @return array
	 */
	public function getLastSearchMetrics() {
		return $this->lastSearchMetrics;
	}
}
