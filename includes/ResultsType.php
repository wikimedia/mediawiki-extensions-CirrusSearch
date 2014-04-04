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
		global $wgCirrusSearchUseExperimentalHighlighter;

		if ( $wgCirrusSearchUseExperimentalHighlighter ) {
			// This is much less esoteric then the plain highlighter based
			// invocation but does the same thing.  The magic is that the none
			// fragmenter still fragments on multi valued fields.
			$entireValue = array(
				'type' => 'experimental',
				'fragmenter' => 'none',
				'number_of_fragments' => 1,
			);
			$entireValueInListField = array(
				'type' => 'experimental',
				'fragmenter' => 'none',
				'order' => 'score',
				'number_of_fragments' => 1,
			);
		} else {
			// This is similar to the FullTextResults type but against the near_match and
			// with the plain highlighter.  Near match because that is how the field is
			// queried.  Plain highlighter because we don't want to add the FVH's space
			// overhead for storing extra stuff and we don't need it for combining fields.
			$entireValue = array(
				'type' => 'plain',
				'number_of_fragments' => 0,
			);
			$entireValueInListField = array(
				'type' => 'plain',
				'fragment_size' => 10000,   // We want the whole value but more than this is crazy
				'order' => 'score',
				'number_of_fragments' => 1, // Just one of the values in the list
			);
		}
		return array(
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
		global $wgCirrusSearchUseExperimentalHighlighter;

		if ( $wgCirrusSearchUseExperimentalHighlighter ) {
			$entireValue = array(
				'type' => 'experimental',
				'fragmenter' => 'none',
				'number_of_fragments' => 1,
			);
			$entireValueInListField = array(
				'type' => 'experimental',
				'fragmenter' => 'none',
				'order' => 'score',
				'number_of_fragments' => 1,
			);
			$singleFragment = array(
				'type' => 'experimental',
				'number_of_fragments' => 1,
				'fragmenter' => 'sentence',
				'options' => array(
					'locale' => wfGetLangObj()->getCode(),
					'top_scoring' => true,
					'boost_before' => array(
						// Note these values are super arbitrary right now.
						'20' => 8,
						'50' => 7,
						'200' => 4,
						'1000' => 2,
					),
				),
			);
			// If there isn't a match just return some of the the first few sentences .
			$text = $singleFragment;
			$text[ 'no_match_size' ] = 100;
		} else {
			$entireValue = array(
				'number_of_fragments' => 0,
				'type' => 'fvh',
				'order' => 'score',
			);
			$entireValueInListField = array(
				'number_of_fragments' => 1, // Just one of the values in the list
				'fragment_size' => 10000,   // We want the whole value but more than this is crazy
				'type' => 'fvh',
				'order' => 'score',
			);
			$singleFragment = array(
				'number_of_fragments' => 1, // Just one fragment
				'fragment_size' => 100,
				'type' => 'fvh',
				'order' => 'score',
			);
			// If there isn't a match just return a match sized chunk from the beginning of the page.
			$text = $singleFragment;
			$text[ 'no_match_size' ] = $text[ 'fragment_size' ];
		}

		return array(
			'pre_tags' => array( Searcher::HIGHLIGHT_PRE ),
			'post_tags' => array( Searcher::HIGHLIGHT_POST ),
			'fields' => $this->addMatchedFields( array(
				'title' => $entireValue,
				'text' => $text,
				'file_text' => $singleFragment,
				'redirect.title' => $entireValueInListField,
				'heading' => $entireValueInListField,
			) ),
		);
	}

	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes,
			$result, $searchContainedSyntax ) {
		return new ResultSet( $suggestPrefixes, $suggestSuffixes, $result, $searchContainedSyntax );
	}

	private function addMatchedFields( $fields ) {
		$newFields = array();
		foreach ( $fields as $name => $config ) {
			$config[ 'matched_fields' ] = array( $name, "$name.plain" );
			$fields[ $name ] = $config;
		}
		return $fields;
	}
}

class InterwikiResultsType implements ResultsType {
	/**
	 * @var string interwiki prefix mappings
	 */
	private $prefix;

	/**
	 * Constructor
	 */
	public function __construct( $interwiki ) {
		$this->prefix = $interwiki;
	}

	public function transformElasticsearchResult( $suggestPrefixes, $suggestSuffixes, $result, $searchContainedSyntax ) {
		return new ResultSet( $suggestPrefixes, $suggestSuffixes, $result, $searchContainedSyntax, $this->prefix );
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
