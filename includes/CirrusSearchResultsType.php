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
	function transformElasticsearchResult( $result );
}

class CirrusSearchTitleResultsType implements CirrusSearchResultsType {
	public function getFields() {
		return array( 'namespace', 'title' );
	}
	public function getHighlightingConfiguration() {
		return null;
	}
	public function transformElasticsearchResult( $result ) {
		$results = array();
		foreach( $result->getResults() as $r ) {
			$results[] = Title::makeTitle( $r->namespace, $r->title )->getPrefixedText();
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
		return array(
			'order' => 'score',
			'pre_tags' => array( CirrusSearchSearcher::HIGHLIGHT_PRE ),
			'post_tags' => array( CirrusSearchSearcher::HIGHLIGHT_POST ),
			'fields' => array(
				'title' => array( 'number_of_fragments' => 0 ),
				'text' => array( 'number_of_fragments' => 1 ),
				'redirect.title' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
				'heading' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
				'title.plain' => array( 'number_of_fragments' => 0 ),
				'text.plain' => array( 'number_of_fragments' => 1 ),
				'redirect.title.plain' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
				'heading.plain' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
			),
		);
	}

	public function transformElasticsearchResult( $result ) {
		return new CirrusSearchResultSet( $result );
	}
}
