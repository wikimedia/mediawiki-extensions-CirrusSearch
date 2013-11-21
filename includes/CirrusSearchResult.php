<?php
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
class CirrusSearchResult extends SearchResult {
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::HIGHLIGHT_PRE
	 */
	private static $highlightPreEscaped = null;
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::HIGHLIGHT_POST
	 */
	private static $highlightPostEscaped = null;

	private $titleSnippet;
	private $redirectTitle, $redirectSnipppet;
	private $sectionTitle, $sectionSnippet;
	private $textSnippet;
	private $wordCount;
	private $byteSize;

	public function __construct( $result ) {
		$title = Title::makeTitle( $result->namespace, $result->title );
		$this->initFromTitle( $title );
		$this->wordCount = $result->text_words;
		$this->byteSize = $result->text_bytes;
		$highlights = $result->getHighlights();
		// Hack for https://github.com/elasticsearch/elasticsearch/issues/3750
		$highlights = $this->swapInPlainHighlighting( $highlights, 'title' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'redirect.title' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'text' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'heading' );
		if ( isset( $highlights[ 'title' ] ) ) {
			$nstext = '';
			if ( $title->getNamespace() !== 0 ) {
				$nstext = $title->getNsText() . ':';
			}
			$this->titleSnippet = $nstext . self::escapeHighlightedText( $highlights[ 'title' ][ 0 ] );
		} else {
			$this->titleSnippet = '';
		}
		if ( !isset( $highlights[ 'title' ] ) && isset( $highlights[ 'redirect.title' ] ) ) {
			// Make sure to find the redirect title before escaping because escaping breaks it....
			$this->redirectTitle = $this->findRedirectTitle( $highlights[ 'redirect.title' ][ 0 ], $result->redirect );
			$this->redirectSnipppet = self::escapeHighlightedText( $highlights[ 'redirect.title' ][ 0 ] );
		} else {
			$this->redirectSnipppet = '';
			$this->redirectTitle = null;
		}
		if ( isset( $highlights[ 'text' ] ) ) {
			$this->textSnippet = self::escapeHighlightedText( $highlights[ 'text' ][ 0 ] );
		} else {
			// This can happen if there the page was sent to Elasticsearch without text.  This could be
			// a bug or it could be that the page simply doesn't have any text.
			$this->textSnippet = '';
		}
		if ( isset( $highlights[ 'heading' ] ) ) {
			$this->sectionSnippet = self::escapeHighlightedText( $highlights[ 'heading' ][ 0 ] );
			$this->sectionTitle = $this->findSectionTitle();
		} else {
			$this->sectionSnippet = '';
			$this->sectionTitle = null;
		}
	}

	/**
	 * Swap plain highlighting into the highlighting field if there isn't any normal highlighting.
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
	private static function escapeHighlightedText( $snippet ) {
		if ( self::$highlightPreEscaped === null ) {
			self::$highlightPreEscaped = htmlspecialchars( CirrusSearchSearcher::HIGHLIGHT_PRE );
			self::$highlightPostEscaped = htmlspecialchars( CirrusSearchSearcher::HIGHLIGHT_POST );
		}
		return str_replace( array( self::$highlightPreEscaped, self::$highlightPostEscaped ),
			array( CirrusSearchSearcher::HIGHLIGHT_PRE, CirrusSearchSearcher::HIGHLIGHT_POST ),
			htmlspecialchars( $snippet ) );
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
		$markers = array( CirrusSearchSearcher::HIGHLIGHT_PRE, CirrusSearchSearcher::HIGHLIGHT_POST );
		return str_replace( $markers, '', $highlighted );
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
}