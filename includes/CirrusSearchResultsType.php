<?php
/**
 * Lightweight classes to describe specific result types we can return
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
interface CirrusSearchResultsType {
	function getFields();
	function getHighlightingConfiguration();
	function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes, $result );
}

class CirrusSearchTitleResultsType implements CirrusSearchResultsType {
	private $getText;

	/**
	 * Build result type.
	 * @param boolean $getText should the results be text (true) or titles (false)
	 */
	public function __construct( $getText ) {
		$this->getText = $getText;
	}

	public function getFields() {
		return array( 'namespace', 'title' );
	}
	public function getHighlightingConfiguration() {
		return null;
	}
	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes, $result ) {
		$results = array();
		foreach( $result->getResults() as $r ) {
			$title = Title::makeTitle( $r->namespace, $r->title );
			if ( $this->getText ) {
				$title = $title->getPrefixedText();
			}
			$results[] = $title;
		}
		return $results;
	}
}

class CirrusSearchFullTextResultsType implements CirrusSearchResultsType {
	public function getFields() {
		return array( 'id', 'title', 'namespace', 'redirect', 'text_bytes', 'text_words' );
	}

	/**
	 * Setup highlighting.
	 * Don't fragment title because it is small.
	 * Get just one fragment from the text because that is all we will display.
	 * Get one fragment from redirect title and heading each or else they
	 * won't be sorted by score.
	 * @return array of highlighting configuration
	 */
	public function getHighlightingConfiguration() {
		$entireValue = array(
			'number_of_fragments' => 0,
		);
		$entireValueInListField = array(
			'number_of_fragments' => 1, // Just one of the values in the list
			'fragment_size' => 10000,   // We want the whole value but more than this is crazy
			'type' => 'plain',          // The fvh doesn't sort list fields by score correctly
		);
		$text = array(
			'number_of_fragments' => 1, // Just one fragment
			'fragment_size' => 100,
		);
		$textWithNoMatch = $text;
		// If there isn't a match just return a match sized chunk from the beginning of the page
		// This is only set on text.plain because we check that text isn't highlighted to return
		// plain matches (horrible hack that will go away one day).  So we can't return anything
		// if there aren't any matches.  But since we still want to return some text and we're
		// already defaulting to the plain match if there isn't any text we can just have it
		// return the chunk.
		$textWithNoMatch[ 'no_match_size' ] = 100;

		return array(
			'order' => 'score',
			'pre_tags' => array( CirrusSearchSearcher::HIGHLIGHT_PRE ),
			'post_tags' => array( CirrusSearchSearcher::HIGHLIGHT_POST ),
			'fields' => array(
				'title' => $entireValue,
				'text' => $text,
				'file_text' => $text,
				'redirect.title' => $entireValueInListField,
				'heading' => $entireValueInListField,
				'title.plain' => $entireValue,
				'text.plain' => $textWithNoMatch,
				'file_text.plain' => $text,
				'redirect.title.plain' => $entireValueInListField,
				'heading.plain' => $entireValueInListField,
			),
		);
	}

	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes, $result ) {
		return new CirrusSearchResultSet( $suggestPrefixes, $suggestSuffixes, $result );
	}
}
