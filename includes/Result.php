<?php

namespace CirrusSearch;
use \MWTimestamp;
use \SearchResult;
use \Title;

/**
 * An individual search result from Elasticsearch.
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
class Result extends SearchResult {
	private $titleSnippet;
	private $redirectTitle, $redirectSnipppet;
	private $sectionTitle, $sectionSnippet;
	private $textSnippet, $isFileMatch;
	private $wordCount;
	private $byteSize;
	private $score;
	private $timestamp;
	private $interwiki;
	private $interwikiNamespace;

	/**
	 * Build the result.
	 * @param $results \Elastica\ResultSet containing all search results
	 * @param $result \Elastica\Result containing the given search result
	 * @param $interwiki Interwiki prefix, if any
	 * @param $result \Elastic\Result containing information about the result this class should represent
	 */
	public function __construct( $results, $result, $interwikis = array() ) {
		global $wgCirrusSearchShowScore;

		$this->maybeSetInterwiki( $result, $interwikis );
		$this->mTitle = Title::makeTitle( ElasticsearchIntermediary::singleValue( $result, 'namespace' ),
			ElasticsearchIntermediary::singleValue( $result, 'title' ), '', $this->interwiki );
		if ( $this->getTitle()->getNamespace() == NS_FILE ) {
			$this->mImage = wfFindFile( $this->mTitle );
		}

		$data = $result->getData();
		// Not all results requested a word count. Just pretend we have none if so
		$this->wordCount = isset( $data['text.word_count'] ) ? $data['text.word_count'] : 0;
		$this->byteSize = ElasticsearchIntermediary::singleValue( $result, 'text_bytes' );
		$this->timestamp = ElasticsearchIntermediary::singleValue( $result, 'timestamp' );
		$this->timestamp = new MWTimestamp( $this->timestamp );
		$highlights = $result->getHighlights();
		// TODO remove when Elasticsearch issue 3757 is fixed
		$highlights = $this->swapInPlainHighlighting( $highlights, 'redirect.title' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'heading' );
		if ( isset( $highlights[ 'title' ] ) ) {
			$nstext = '';
			if ( $this->getTitle()->getNamespace() !== 0 ) {
				// We have to replace the _s because getNsText spits them out....
				$nstext = str_replace( '_', ' ', $this->getTitle()->getNsText() ) . ':';
			}
			$this->titleSnippet = $nstext . $this->escapeHighlightedText( $highlights[ 'title' ][ 0 ] );
		} else {
			$this->titleSnippet = '';
		}
		if ( !isset( $highlights[ 'title' ] ) && isset( $highlights[ 'redirect.title' ] ) ) {
			// Make sure to find the redirect title before escaping because escaping breaks it....

			// This odd juggling is the second half of the script fields hack to get redirect loaded.
			// It'll go away when we switch to source filtering.
			$redirects = $result->redirect;
			if ( $redirects !== null ) {
				// I not null it'll always be an array.
				// In Elasticsearch 0.90 it'll be an array of arrays which is what we need.
				// In Elasticsearch 1.0 it'll be an array of arrays of arrays where the outer most array
				// has only a single element which is exactly what would come back from 0.90.
				if ( count( $redirects ) !== 0 && !array_key_exists( 'title', $redirects[ 0 ] ) ) {
					wfDebugLog( 'CirrusSearch', "1.0");
					// Since the first entry doesn't have a title we assume we're in 1.0
					$redirects = $redirects[ 0 ];
				}
			}
			$this->redirectTitle = $this->findRedirectTitle( $highlights[ 'redirect.title' ][ 0 ], $redirects );
			$this->redirectSnipppet = $this->escapeHighlightedText( $highlights[ 'redirect.title' ][ 0 ] );
		} else {
			$this->redirectSnipppet = '';
			$this->redirectTitle = null;
		}
		if ( isset( $highlights[ 'text' ] ) ) {
			$snippet = $highlights[ 'text' ][ 0 ];
			if ( isset( $highlights[ 'file_text' ] ) ) {
				$fileTextSnippet = $highlights[ 'file_text' ][ 0 ];
				if ( !$this->containsMatches( $snippet ) && $this->containsMatches( $fileTextSnippet ) ) {
					$snippet = $fileTextSnippet;
					$this->isFileMatch = true;
				}
			}
			$this->textSnippet = $this->escapeHighlightedText( $snippet );
		} else {
			// This can happen if there the page was sent to Elasticsearch without text.  This could be
			// a bug or it could be that the page simply doesn't have any text.
			$this->textSnippet = '';
		}
		if ( isset( $highlights[ 'heading' ] ) ) {
			$this->sectionSnippet = $this->escapeHighlightedText( $highlights[ 'heading' ][ 0 ] );
			$this->sectionTitle = $this->findSectionTitle();
		} else {
			$this->sectionSnippet = '';
			$this->sectionTitle = null;
		}
		if ( $wgCirrusSearchShowScore && $results->getMaxScore() ) {
			$this->score = $result->getScore() / $results->getMaxScore();
		}
	}

	/**
	 * Assume we're never missing. We always know about page updates
	 * @return bool
	 */
	public function isMissingRevision() {
		return false;
	}

	/**
	 * Swap plain highlighting into the highlighting field if there isn't any normal highlighting.
	 * TODO remove when Elasticsearch issue 3757 is fixed.
	 * @var $highlights array of highlighting results
	 * @var $name string normal field name
	 * @return $highlights with $name replaced with plain field results if $name isn't in $highlights
	 */
	private function swapInPlainHighlighting( $highlights, $name ) {
		if ( !isset( $highlights[ $name ] ) && isset( $highlights[ "$name.plain" ] ) ) {
			$highlights[ $name ] = $highlights[ "$name.plain" ];
		}
		return $highlights;
	}

	/**
	 * Escape highlighted text coming back from Elasticsearch.
	 * @param $snippet string highlighted snippet returned from elasticsearch
	 * @return string $snippet with html escaped _except_ highlighting pre and post tags
	 */
	private function escapeHighlightedText( $snippet ) {
		static $highlightPreEscaped = null, $highlightPostEscaped = null;
		if ( $highlightPreEscaped === null ) {
			$highlightPreEscaped = htmlspecialchars( Searcher::HIGHLIGHT_PRE );
			$highlightPostEscaped = htmlspecialchars( Searcher::HIGHLIGHT_POST );
		}
		return str_replace( array( $highlightPreEscaped, $highlightPostEscaped ),
			array( Searcher::HIGHLIGHT_PRE, Searcher::HIGHLIGHT_POST ),
			htmlspecialchars( $snippet ) );
	}

	/**
	 * Checks if a snippet contains matches by looking for HIGHLIGHT_PRE.
	 * @param string $snippet highlighted snippet returned from elasticsearch
	 * @return boolean true if $snippet contains matches, false otherwise
	 */
	private function containsMatches( $snippet ) {
		return strpos( $snippet, Searcher::HIGHLIGHT_PRE ) !== false;
	}

	/**
	 * Build the redirect title from the highlighted redirect snippet.
	 * @param string highlighted redirect snippet
	 * @param array $redirects Array of redirects stored as arrays with 'title' and 'namespace' keys
	 * @return Title object representing the redirect
	 */
	private function findRedirectTitle( $snippet, $redirects ) {
		$title = $this->stripHighlighting( $snippet );
		// Grab the redirect that matches the highlighted title with the lowest namespace.
		// That is pretty arbitrary but it prioritizes 0 over others.
		$best = null;
		foreach ( $redirects as $redirect ) {
			if ( $redirect[ 'title' ] === $title && ( $best === null || $best[ 'namespace' ] > $redirect ) ) {
				$best = $redirect;
			}
		}
		if ( $best === null ) {
			wfLogWarning( "Search backend highlighted a redirect ($title) but didn't return it." );
			return null;
		}
		return Title::makeTitleSafe( $best[ 'namespace' ], $best[ 'title' ] );
	}

	private function findSectionTitle() {
		$heading = $this->stripHighlighting( $this->sectionSnippet );
		return Title::makeTitle(
			$this->getTitle()->getNamespace(),
			$this->getTitle()->getDBkey(),
			Title::escapeFragmentForURL( $heading )
		);
	}

	private function stripHighlighting( $highlighted ) {
		$markers = array( Searcher::HIGHLIGHT_PRE, Searcher::HIGHLIGHT_POST );
		return str_replace( $markers, '', $highlighted );
	}

	private function maybeSetInterwiki( $result, $interwikis ) {
		$iw = '';
		$iwNS = '';
		array_walk( $interwikis, function( $indexBase, $interwiki ) use ( $result, &$iw, &$iwNS ) {
			$index = $result->getIndex();
			$pos = strpos( $index, $indexBase );
			if ( $pos === 0 && $index[strlen( $indexBase )] == '_' ) {
				$iw = $interwiki;
				$iwNS = $result->namespace_text ? $result->namespace_text : '';
			}
		} );
		$this->interwiki = $iw;
		$this->interwikiNamespace = $iwNS;
	}

	public function getTitleSnippet( $terms ) {
		return $this->titleSnippet;
	}

	public function getRedirectTitle() {
		return $this->redirectTitle;
	}

	public function getRedirectSnippet( $terms ) {
		return $this->redirectSnipppet;
	}

	public function getTextSnippet( $terms ) {
		return $this->textSnippet;
	}

	public function getSectionSnippet() {
		return $this->sectionSnippet;
	}

	public function getSectionTitle() {
		return $this->sectionTitle;
	}

	public function getWordCount() {
		return $this->wordCount;
	}

	public function getByteSize() {
		return $this->byteSize;
	}

	public function getScore() {
		return $this->score;
	}

	public function getTimestamp() {
		return $this->timestamp->getTimestamp( TS_MW );
	}

	public function isFileMatch() {
		return $this->isFileMatch;
	}

	public function getInterwikiPrefix() {
		return $this->interwiki;
	}

	public function getInterwikiNamespaceText() {
		return $this->interwikiNamespace;
	}
}
