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
	/**
	 * Get the source filtering to be used loading the result.
	 * @return false|string|array corresonding to Elasticsearch source filtering syntax
	 */
	function getSourceFiltering();
	/**
	 * Get the fields to load.  Most of the time we'll use source filtering instead but
	 * some fields aren't part of the source.
	 * @return false|string|array corresponding to Elasticsearch fields syntax
	 */
	function getFields();
	function getHighlightingConfiguration();
	function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes,
		$result, $searchContainedSyntax );
}

class TitleResultsType implements ResultsType {
	private $getText;
	private $matchedAnalyzer;

	/**
	 * Build result type.   The matchedAnalyzer is required to detect if the match
	 * was from the title or a redirect (and is kind of a leaky abstraction.)
	 * @param boolean $getText should the results be text (true) or titles (false)
	 * @param string $matchedAnalyzer the analyzer used to match the title
	 */
	public function __construct( $getText, $matchedAnalyzer ) {
		$this->getText = $getText;
		$this->matchedAnalyzer = $matchedAnalyzer;
	}

	public function getSourceFiltering() {
		return array( 'namespace', 'title' );
	}

	public function getFields() {
		return false;
	}

	public function getHighlightingConfiguration() {
		// This is similar to the FullTextResults type but against the near_match and
		// with the plain highlighter.  Near match because that is how the field is
		// queried.  Plain highlighter because we don't want to add the FVH's space
		// overhead for storing extra stuff and we don't need it for combining fields.
		$entireValue = array(
			'number_of_fragments' => 0,
			'type' => 'plain',
		);
		$entireValueInListField = array(
			'number_of_fragments' => 1, // Just one of the values in the list
			'fragment_size' => 10000,   // We want the whole value but more than this is crazy
			'type' => 'plain',
		);
		return array(
			'order' => 'score',
			'pre_tags' => array( Searcher::HIGHLIGHT_PRE ),
			'post_tags' => array( Searcher::HIGHLIGHT_POST ),
			'fields' => array(
				"title.$this->matchedAnalyzer" => $entireValue,
				"redirect.title.$this->matchedAnalyzer" => $entireValueInListField,
			)
		);
	}
	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes,
			$result, $searchContainedSyntax ) {
		$results = array();
		foreach( $result->getResults() as $r ) {
			$title = Title::makeTitle( $r->namespace, $r->title );
			$highlights = $r->getHighlights();

			// Now we have to use the highlights to figure out whether it was the title or the redirect
			// that matched.  It is kind of a shame we can't really give the highlighting to the client
			// though.
			if ( isset( $highlights[ "redirect.title.$this->matchedAnalyzer" ] ) ) {
				// The match was against a redirect so we should replace the $title with one that
				// represents the redirect.
				// The first step is to strip the actual highlighting from the title.
				$redirectTitle = $highlights[ "redirect.title.$this->matchedAnalyzer" ][ 0 ];
				$redirectTitle = str_replace( Searcher::HIGHLIGHT_PRE, '', $redirectTitle );
				$redirectTitle = str_replace( Searcher::HIGHLIGHT_POST, '', $redirectTitle );

				// Instead of getting the redirect's real namespace we're going to just use the namespace
				// of the title.  This is not great but OK given that we can't find cross namespace
				// redirects properly any way.
				$title = Title::makeTitle( $r->namespace, $redirectTitle );
			} else if ( !isset( $highlights[ "title.$this->matchedAnalyzer" ] ) ) {
				// We're not really sure where the match came from so lets just pretend it was the title?
				wfDebugLog( 'CirrusSearch', "Title search result type hit a match but we can't " .
					"figure out what caused the match:  $r->namespace:$r->title");
			}
			if ( $this->getText ) {
				$title = $title->getPrefixedText();
			}
			$results[] = $title;
		}
		return $results;
	}
}

class FullTextResultsType implements ResultsType {
	public function getSourceFiltering() {
		return array( 'id', 'title', 'namespace', 'redirect.*', 'timestamp', 'text_bytes' );
	}

	public function getFields() {
		return "text.word_count"; // word_count is only a stored field and isn't part of the source.
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

class InterwikiResultsType implements ResultsType {
	/**
	 * @var array interwiki prefix mappings
	 */
	private $prefixes;

	/**
	 * Constructor
	 */
	public function __construct( $interwikis ) {
		$this->prefixes = $interwikis;
	}

	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes, $result, $searchContainedSyntax ) {
		return new ResultSet( $suggestPrefixes, $suggestSuffixes, $result, $searchContainedSyntax, $this->prefixes );
	}

	public function getHighlightingConfiguration() {
		return null;
	}

	public function getSourceFiltering() {
		return array( 'namespace', 'namespace_text', 'title' );
	}

	public function getFields() {
		return false;
	}
}
