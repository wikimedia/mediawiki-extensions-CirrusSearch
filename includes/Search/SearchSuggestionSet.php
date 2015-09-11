<?php

namespace CirrusSearch\Search;

/**
 * Search suggestion sets
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
 *
 */

/**
 * A set of SearchSuggestions
 */
class SearchSuggestionSet {
	/**
	 * @var SearchSuggestion[]
	 */
	private $suggestions;

	/**
	 * Builds a new set of suggestions.
	 *
	 * NOTE: the array should be sorted by score (higher is better),
	 * SearchSuggestionSet will not try to re-order this input array.
	 * Providing an unsorted input array is a mistake and will lead to
	 * unexpected behaviors.
	 *
	 * @param SearchSuggestion[] $suggestions (must be sorted by score)
	 */
	public function __construct( array $suggestions ) {
		$this->suggestions = array_values( $suggestions );
	}

	public function getSuggestions() {
		return $this->suggestions;
	}

	/**
	 * Call array_map on the suggestions array
	 * @param callback $callback
	 * @return array
	 */
	public function map( $callback ) {
		return array_map( $callback, $this->suggestions );
	}

	/**
	 * Add a new suggestion at the end.
	 * If the score of the new suggestion is greater than the worst one,
	 * the new suggestion score will be updated (worst - 1).
	 *
	 * @param SearchSuggestion $suggestion
	 */
	public function addSuggestion( SearchSuggestion $suggestion ) {
		if ( $this->getSize() > 0 && $suggestion->getScore() >= $this->getWorstScore() ) {
			$suggestion->setScore( $this->getWorstScore() - 1);
		}
		$this->suggestions[] = $suggestion;
	}

	/**
	 * Move the suggestion at index $key to the first position
	 */
	public function rescore( $key ) {
		$removed = array_splice( $this->suggestions, $key, 1 );
		$this->insertBestSuggestion( $removed[0] );
	}

	/**
	 * Add a new suggestion at the top. If the new suggestion score
	 * is lower than the best one its score will be updated (best + 1)
	 * @param SearchSuggestion $suggestion
	 */
	public function insertBestSuggestion( SearchSuggestion $suggestion ) {
		if( $this->getSize() > 0 && $suggestion->getScore() <= $this->getBestScore() ) {
			$suggestion->setScore( $this->getBestScore() + 1 );
		}
		array_unshift( $this->suggestions, $suggestion );
	}

	/**
	 * @return float the best score in this suggestion set
	 */
	public function getBestScore() {
		if ( empty( $this->suggestions ) ) {
			return 0;
		}
		return reset( $this->suggestions )->getScore();
	}

	/**
	 * @return float the worst score in this set
	 */
	public function getWorstScore() {
		if ( empty( $this->suggestions ) ) {
			return 0;
		}
		return end( $this->suggestions )->getScore();
	}

	/**
	 * @return int the number of suggestion in this set
	 */
	public function getSize() {
		return count( $this->suggestions );
	}

	/**
	 * Remove any extra elements in the suggestions set
	 * @param int $limit the max size of this set.
	 */
	public function shrink( $limit ) {
		if ( count( $this->suggestions ) > $limit ) {
			$this->suggestions = array_slice( $this->suggestions, 0, $limit );
		}
	}

	/**
	 * Builds a new set of suggestion based on a title array.
	 * Useful when using a backend that supports only Titles.
	 *
	 * NOTE: Suggestion scores will be generated.
	 *
	 * @param Title[] $titles
	 * @return SearchSuggestionSet
	 */
	public static function fromTitles( array $titles ) {
		$suggestions = array();
		$score = count( $titles );
		foreach( $titles as $title ) {
			$suggestions[] = new SearchSuggestion( $title->getPrefixedText(), wfExpandUrl( $title->getFullURL(), PROTO_CURRENT ), $score--, $title );
		}
		return new SearchSuggestionSet( $suggestions );
	}

	/**
	 * @return SearchSuggestionSet an empty suggestion set
	 */
	public static function emptySuggestionSet() {
		return new SearchSuggestionSet( array() );
	}
}
