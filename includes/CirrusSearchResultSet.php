<?php
/**
 * A set of results from Elasticsearch.
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
class CirrusSearchResultSet extends SearchResultSet {
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_PRE
	 */
	private static $suggestionHighlightPreEscaped = null;
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_POST
	 */
	private static $suggestionHighlightPostEscaped = null;

	private $result, $hits, $totalHits, $suggestionQuery, $suggestionSnippet;

	public function __construct( $res ) {
		$this->result = $res;
		$this->hits = $res->count();
		$this->totalHits = $res->getTotalHits();
		$suggestion = $this->findSuggestion();
		$this->suggestionQuery = $suggestion[ 'text' ];
		$this->suggestionSnippet = self::escapeHighlightedSuggestion( $suggestion[ 'highlighted' ] );
	}

	private function findSuggestion() {
		// TODO some kind of weighting?
		$suggest = $this->result->getResponse()->getData();
		if ( !isset( $suggest[ 'suggest' ] ) ) {
			return null;
		}
		$suggest = $suggest[ 'suggest' ];
		// Elasticsearch will send back the suggest element but no sub suggestion elements if the wiki is empty.
		// So we should check to see if they exist even though in normal operation they always will.
		if ( isset( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TITLE ] ) ) {
			foreach ( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TITLE ][ 0 ][ 'options' ] as $option ) {
				return $option;
			}
		}
		// If the user doesn't search against redirects we don't check them for suggestions so the result might not be there.
		if ( isset( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_REDIRECT ] ) ) {
			foreach ( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_REDIRECT ][ 0 ][ 'options' ] as $option ) {
				return $option;
			}
		}
		// This suggestion type is optional, configured in LocalSettings.
		if ( isset( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TEXT ] ) ) {
			foreach ( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TEXT ][ 0 ][ 'options' ] as $option ) {
				return $option;
			}
		}
		return null;
	}

	/**
	 * Escape a highlighted suggestion coming back from Elasticsearch.
	 * @param $suggestion string suggestion from elasticsearch
	 * @return string $suggestion with html escaped _except_ highlighting pre and post tags
	 */
	private static function escapeHighlightedSuggestion( $suggestion ) {
		if ( self::$suggestionHighlightPreEscaped === null ) {
			self::$suggestionHighlightPreEscaped =
				htmlspecialchars( CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_PRE );
			self::$suggestionHighlightPostEscaped =
				htmlspecialchars( CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_POST );
		}
		return str_replace( array( self::$suggestionHighlightPreEscaped, self::$suggestionHighlightPostEscaped ),
			array( CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_PRE, CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_POST ),
			htmlspecialchars( $suggestion ) );
	}

	public function hasResults() {
		return $this->totalHits > 0;
	}

	public function getTotalHits() {
		return $this->totalHits;
	}

	public function numRows() {
		return $this->hits;
	}

	public function hasSuggestion() {
		return $this->suggestionQuery !== null;
	}

	public function getSuggestionQuery() {
		return $this->suggestionQuery;
	}

	public function getSuggestionSnippet() {
		return $this->suggestionSnippet;
	}

	public function next() {
		$current = $this->result->current();
		if ( $current ) {
			$this->result->next();
			return new CirrusSearchResult( $current );
		}
		return false;
	}

	public function getInterwikiResults() {
	}
}
