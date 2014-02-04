<?php

namespace CirrusSearch;
use \Title;

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
interface ResultsType {
	function getFields();
	function getHighlightingConfiguration();
	function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes,
		$result, $searchContainedSyntax );
}

class TitleResultsType implements ResultsType {
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
	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes,
			$result, $searchContainedSyntax ) {
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

class FullTextResultsType implements ResultsType {
	public function getFields() {
		// TODO remove text_words once text.word_count is available everywhere
		return array( 'id', 'title', 'namespace', 'redirect', 'timestamp',
			'text_bytes', 'text.word_count', 'text_words' );
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
			'type' => 'fvh',
		);
		$entireValueInListField = array(
			'number_of_fragments' => 1, // Just one of the values in the list
			'fragment_size' => 10000,   // We want the whole value but more than this is crazy
			'type' => 'plain',          // TODO switch to fvh when Elasticserach issue 3757 is fixed
		);
		$singleFragment = array(
			'number_of_fragments' => 1, // Just one fragment
			'fragment_size' => 100,
			'type' => 'fvh',
		);

		// If there isn't a match just return a match sized chunk from the beginning of the page.
		$text = $singleFragment;
		$text[ 'no_match_size' ] = $text[ 'fragment_size' ];

		return array(
			'order' => 'score',
			'pre_tags' => array( Searcher::HIGHLIGHT_PRE ),
			'post_tags' => array( Searcher::HIGHLIGHT_POST ),
			'fields' => $this->addMatchedFields( array(
				'title' => $entireValue,
				'text' => $text,
				'file_text' => $singleFragment,
				'redirect.title' => $entireValueInListField,
				'heading' => $entireValueInListField,
				// TODO remove when Elasticsearch issue 3757 is fixed
				'redirect.title.plain' => $entireValueInListField,
				'heading.plain' => $entireValueInListField,
			) ),
		);
	}

	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes,
			$result, $searchContainedSyntax ) {
		return new ResultSet( $suggestPrefixes, $suggestSuffixes, $result, $searchContainedSyntax );
	}

	private function addMatchedFields( $fields ) {
		foreach ( $fields as $name => $config ) {
			// TODO remove when Elasticsearch issue 3757 is fixed
			if ( $config[ 'type' ] !== 'fvh' ) {
				continue;
			}
			$config[ 'matched_fields' ] = array( $name, "$name.plain" );
			$fields[ $name ] = $config;
		}
		return $fields;
	}
}
