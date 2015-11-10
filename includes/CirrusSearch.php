<?php

use CirrusSearch\Connection;
use CirrusSearch\InterwikiSearcher;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Searcher;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\SearchConfig;

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
	const MORE_LIKE_THIS_JUST_WIKIBASE_PREFIX = 'morelikewithwikibase:';

	/**
	 * @var string The last prefix substituted by replacePrefixes.
	 */
	private $lastNamespacePrefix;

	/**
	 * @var array metrics about the last thing we searched
	 */
	private $lastSearchMetrics;

	/**
	 * @var string
	 */
	private $indexBaseName;

	/**
	 * @var Connection
	 */
	private $connection;

	public function __construct( $baseName = null ) {
		$this->indexBaseName = $baseName === null ? wfWikiId() : $baseName;
		$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		$this->connection = new Connection( $config );
	}

	public function setConnection( Connection $connection ) {
		$this->connection = $connection;
	}

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
	 * @return ResultSet|null|Status results, no results, or error respectively
	 */
	public function searchText( $term ) {
		$config = null;
		$request = RequestContext::getMain()->getRequest();
		if ( $request && $request->getVal( 'cirrusLang' ) ) {
			$config = new SearchConfig( $request->getVal( 'cirrusLang' ) );
		}
		$matches = $this->searchTextReal( $term, $config );
		if (!$matches instanceof ResultSet) {
			return $matches;
		}

		if ( $this->isFeatureEnabled( 'rewrite' ) &&
				$matches->isQueryRewriteAllowed( $GLOBALS['wgCirrusSearchInterwikiThreshold'] ) ) {
			$matches = $this->searchTextSecondTry( $term, $matches );
		}
		return $matches;
	}

	/**
	 * Check whether we want to try another language.
	 * @param string $term Search term
	 * @return array|null Array of (interwiki, dbname) for another wiki to try, or null
	 */
	private function hasSecondaryLanguage( $term ) {
		if ( empty( $GLOBALS['wgCirrusSearchLanguageToWikiMap'] ) ||
				empty( $GLOBALS['wgCirrusSearchWikiToNameMap'] ) ) {
			// map's empty - no need to bother with detection
			return null;
		}

		// check whether we have second language functionality enabled
		if ( !$GLOBALS['wgCirrusSearchEnableAltLanguage'] ) {
			return null;
		}

		$lang = Searcher::detectLanguage( $this->connection, $term );
		if ( empty( $GLOBALS['wgCirrusSearchLanguageToWikiMap'][$lang] ) ) {
			return null;
		}
		$interwiki = $GLOBALS['wgCirrusSearchLanguageToWikiMap'][$lang];

		if ( empty( $GLOBALS['wgCirrusSearchWikiToNameMap'][$interwiki] ) ) {
			return null;
		}
		$interWikiId = $GLOBALS['wgCirrusSearchWikiToNameMap'][$interwiki];
		if ( $interWikiId == wfWikiID() ) {
			// we're back to the same wiki, no use to try again
			return null;
		}

		return array( $interwiki, $interWikiId );
	}

	private function isFeatureEnabled( $feature ) {
		return isset( $this->features[$feature] ) && $this->features[$feature];
	}

	private function searchTextSecondTry( $term, ResultSet $oldResult ) {
		// TODO: figure out who goes first - language or suggestion?
		if ( $oldResult->numRows() == 0 && $oldResult->hasSuggestion() ) {
			$rewritten = $oldResult->getSuggestionQuery();
			$rewrittenSnippet = $oldResult->getSuggestionSnippet();
			$this->showSuggestion = false;
			$rewrittenResult = $this->searchTextReal( $rewritten );
			if (
				$rewrittenResult instanceof ResultSet
				&& $rewrittenResult->numRows() > 0
			) {
				$rewrittenResult->setRewrittenQuery( $rewritten, $rewrittenSnippet );
				if ( $rewrittenResult->numRows() < $GLOBALS['wgCirrusSearchInterwikiThreshold'] ) {
					// replace the result but still try the alt language
					$oldResult = $rewrittenResult;
				} else {
					return $rewrittenResult;
				}
			}
		}

		$altWiki = $this->hasSecondaryLanguage( $term );
		if ( $altWiki ) {
			try {
				$config = new SearchConfig( $altWiki[0], $altWiki[1] );
			} catch ( MWException $e ) {
				wfDebug( "Failed to get $altWiki config: {$e->getMessage()}");
				$config = null;
			}
			if ( $config ) {
				$matches = $this->searchTextReal( $term, $config );
				if( $matches instanceof ResultSet && $matches->numRows() > 0 ) {
					$oldResult->addInterwikiResults( $matches, SearchResultSet::INLINE_RESULTS, $altWiki[1] );
				}
			}
		}

		// Don't have any other options yet.
		return $oldResult;
	}

	/**
	 * Do the hard part of the searching - actual Searcher invocation
	 * @param string $term
	 * @param SearchConfig $config
	 * @return NULL|Status|ResultSet
	 */
	private function searchTextReal( $term, SearchConfig $config = null ) {
		global $wgCirrusSearchInterwikiSources;

		// Convert the unicode character 'idiographic whitespace' into standard
		// whitespace.  Cirrussearch treats them both as normal whitespace, but
		// the preceding isn't appropriatly trimmed.
		$term = trim( str_replace( "\xE3\x80\x80", " ", $term) );
		// No searching for nothing! That takes forever!
		if ( !$term ) {
			return null;
		}

		$context = RequestContext::getMain();
		$request = $context->getRequest();
		$user = $context->getUser();

		if ( $config ) {
			$this->indexBaseName = $config->getWikiId();
		}

		$searcher = new Searcher( $this->connection, $this->offset, $this->limit, $config, $this->namespaces, $user, $this->indexBaseName );

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
			$searcher->addSuggestPrefix( '~' );
		}

		// TODO remove this when we no longer have to support core versions without
		// Ie946150c6796139201221dfa6f7750c210e97166
		if ( method_exists( $this, 'getSort' ) ) {
			$searcher->setSort( $this->getSort() );
		}

		$dumpQuery = $request && $request->getVal( 'cirrusDumpQuery' ) !== null;
		$searcher->setReturnQuery( $dumpQuery );
		$dumpResult = $request && $request->getVal( 'cirrusDumpResult' ) !== null;
		$searcher->setDumpResult( $dumpResult );
		$returnExplain = $request && $request->getVal( 'cirrusExplain' ) !== null;
		$searcher->setReturnExplain( $returnExplain );

		// Delegate to either searchText or moreLikeThisArticle and dump the result into $status
		if ( substr( $term, 0, strlen( self::MORE_LIKE_THIS_PREFIX ) ) === self::MORE_LIKE_THIS_PREFIX ) {
			$term = substr( $term, strlen( self::MORE_LIKE_THIS_PREFIX ) );
			$status = $this->moreLikeThis( $term, $searcher, Searcher::MORE_LIKE_THESE_NONE );
		} else if ( substr( $term, 0, strlen( self::MORE_LIKE_THIS_JUST_WIKIBASE_PREFIX ) ) === self::MORE_LIKE_THIS_JUST_WIKIBASE_PREFIX ) {
			$term = substr( $term, strlen( self::MORE_LIKE_THIS_JUST_WIKIBASE_PREFIX ) );
			$status = $this->moreLikeThis( $term, $searcher, Searcher::MORE_LIKE_THESE_ONLY_WIKIBASE );
		} else {
			# Namespace lookup should not be done for morelike special syntax (T111244)
			if ( $this->lastNamespacePrefix ) {
				$searcher->addSuggestPrefix( $this->lastNamespacePrefix );
			} else {
				$searcher->updateNamespacesFromQuery( $term );
			}
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
				if ( $request->getVal( 'cirrusHighlightDefaultSimilarity' ) === 'no' ) {
					$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_WITH_DEFAULT_SIMILARITY;
				}
				if ( $request->getVal( 'cirrusHighlightAltTitleWithPostings' ) === 'no' ) {
					$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_ALT_TITLES_WITH_POSTINGS;
				}
			}
			if ( $this->namespaces && !in_array( NS_FILE, $this->namespaces ) ) {
				$highlightingConfig ^= FullTextResultsType::HIGHLIGHT_FILE_TEXT;
			}

			$searcher->setResultsType( new FullTextResultsType( $highlightingConfig, $config ? $config->getWikiCode() : '') );
			$status = $searcher->searchText( $term, $this->showSuggestion );
		}
		if ( $dumpQuery || $dumpResult ) {
			// When dumping the query we skip _everything_ but echoing the query.
			$context->getOutput()->disable();
			$request->response()->header( 'Content-type: application/json; charset=UTF-8' );
			if ( $status->getValue() === null ) {
				echo '{}';
			} else {
				echo json_encode( $status->getValue() );
			}
			exit();
		}

		$this->lastSearchMetrics = $searcher->getSearchMetrics();

		// Add interwiki results, if we have a sane result
		// Note that we have no way of sending warning back to the user.  In this case all warnings
		// are logged when they are added to the status object so we just ignore them here....
		if ( $status->isOK() && $wgCirrusSearchInterwikiSources && $status->getValue() &&
				method_exists( $status->getValue(), 'addInterwikiResults' ) ) {
			// @todo @fixme: This should absolutely be a multisearch. I knew this when I
			// wrote the code but Searcher needs some refactoring first.
			foreach ( $wgCirrusSearchInterwikiSources as $interwiki => $index ) {
				$iwSearch = new InterwikiSearcher( $this->connection, $this->namespaces, $user, $index, $interwiki );
				$interwikiResult = $iwSearch->getInterwikiResults( $term );
				if ( $interwikiResult ) {
					$status->getValue()->addInterwikiResults( $interwikiResult, SearchResultSet::SECONDARY_RESULTS, $interwiki );
				}
			}
		}

		// For historical reasons all callers of searchText interpret any Status return as an error
		// so we must unwrap all OK statuses.  Note that $status can be "good" and still contain null
		// since that is interpreted as no results.
		return $status->isOk() ? $status->getValue() : $status;
	}

	private function moreLikeThis( $term, $searcher, $options ) {
		// Expand titles chasing through redirects
		$titles = array();
		$found = array();
		foreach ( explode( '|', $term ) as $title ) {
			$title = Title::newFromText( trim( $title ) );
			while ( true ) {
				if ( !$title ) {
					continue 2;
				}
				$titleText = $title->getFullText();
				if ( in_array( $titleText, $found ) ) {
					continue 2;
				}
				$found[] = $titleText;
				if ( !$title->exists() ) {
					continue 2;
				}
				if ( $title->isRedirect() ) {
					$page = WikiPage::factory( $title );
					if ( !$page->exists() ) {
						continue 2;
					}
					$title = $page->getRedirectTarget();
				} else {
					break;
				}
			}
			$titles[] = $title;
		}
		if ( count( $titles ) ) {
			return $searcher->moreLikeTheseArticles( $titles, $options );
		}
		return Status::newGood( new SearchResultSet( true ) /* empty */ );
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
		return array( 'relevance', 'title_asc', 'title_desc' );
	}

	/**
	 * Get the metrics for the last search we performed. Null if we haven't done any.
	 * @return array
	 */
	public function getLastSearchMetrics() {
		return $this->lastSearchMetrics;
	}
}
