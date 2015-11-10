<?php

namespace CirrusSearch\Search;
use \CirrusSearch\Searcher;
use \LinkBatch;
use \SearchResultSet;

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
class ResultSet extends SearchResultSet {
	/**
	 * @var Elastica\ResultSet
	 */
	private $result;
	private $hits, $totalHits, $suggestionQuery, $suggestionSnippet;
	private $searchContainedSyntax;
	private $interwikiPrefix,$interwikiResults;
	private $rewrittenQuery;

	public function __construct( $suggestPrefixes, $suggestSuffixes, $res, $searchContainedSyntax, $interwiki = '' ) {
		$this->result = $res;
		$this->searchContainedSyntax = $searchContainedSyntax;
		$this->hits = $res->count();
		$this->totalHits = $res->getTotalHits();
		$this->interwikiPrefix = $interwiki;
		$this->preCacheContainedTitles( $this->result );
		$suggestion = $this->findSuggestion();
		if ( $suggestion && ! $this->resultContainsFullyHighlightedMatch() ) {
			$this->suggestionQuery = $suggestion[ 'text' ];
			$this->suggestionSnippet = $this->escapeHighlightedSuggestion( $suggestion[ 'highlighted' ] );
			if ( $suggestPrefixes ) {
				$suggestPrefix = implode( ' ', $suggestPrefixes );
				// No need to escape suggestionQuery because Linker will escape it.
				$this->suggestionQuery = $suggestPrefix . $this->suggestionQuery;
				$this->suggestionSnippet = htmlspecialchars( $suggestPrefix ) . $this->suggestionSnippet;
			}
			if ( $suggestSuffixes ) {
				$suggestSuffix = implode( ' ', $suggestSuffixes );
				// No need to escape suggestionQuery because Linker will escape it.
				$this->suggestionQuery = $this->suggestionQuery . $suggestSuffix;
				$this->suggestionSnippet = $this->suggestionSnippet . htmlspecialchars( $suggestSuffix );
			}
		}
	}

	/**
	 * Is rewriting this query OK?
	 * @param int $threshold Minimum number of results to reach before rewriting is not allowed.
	 * @return bool True when rewriting this query is allowed
	 */
	public function isQueryRewriteAllowed( $threshold = 1 ) {
		if ( $this->numRows() >= $threshold || $this->searchContainedSyntax() ) {
			return false;
		}
		foreach ( $this->getInterwikiResults( SearchResultSet::SECONDARY_RESULTS ) as $resultSet ) {
			if ( $resultSet->numRows() >= $threshold ) {
				return false;
			}
		}
		return true;
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
		if ( isset( $suggest[ 'suggest' ] ) ) {
			// Now just grab the first one it sent back.
			foreach ( $suggest[ 'suggest' ][ 0 ][ 'options' ] as $option ) {
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
	private function escapeHighlightedSuggestion( $suggestion ) {
		static $suggestionHighlightPreEscaped = null,
			$suggestionHighlightPostEscaped = null;
		if ( $suggestionHighlightPreEscaped === null ) {
			$suggestionHighlightPreEscaped =
				htmlspecialchars( Searcher::SUGGESTION_HIGHLIGHT_PRE );
			$suggestionHighlightPostEscaped =
				htmlspecialchars( Searcher::SUGGESTION_HIGHLIGHT_POST );
		}
		return str_replace( array( $suggestionHighlightPreEscaped, $suggestionHighlightPostEscaped ),
			array( Searcher::SUGGESTION_HIGHLIGHT_PRE, Searcher::SUGGESTION_HIGHLIGHT_POST ),
			htmlspecialchars( $suggestion ) );
	}

	private function resultContainsFullyHighlightedMatch() {
		foreach ( $this->result->getResults() as $result ) {
			$highlights = $result->getHighlights();
			// If the whole string is highlighted then return true
			if ( isset( $highlights[ 'title' ] ) &&
					!trim( preg_replace( Searcher::HIGHLIGHT_REGEX, '', $highlights[ 'title' ][ 0 ] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Loads the result set into the mediawiki LinkCache via a
	 * batch query. By pre-caching this we ensure methods such as
	 * Result::isMissingRevision() don't trigger a query for each and
	 * every search result.
	 * @param ResultSet $resultSet Result set from which the titles come
	 */
	private function preCacheContainedTitles( $resultSet ) {
		// We can only pull in information about the local wiki
 		if ( $this->interwikiPrefix !== '' ) {
 			return;
 		}
		$lb = new LinkBatch;
		foreach ( $resultSet->getResults() as $result ) {
			$lb->add( $result->namespace, $result->title );
		}
		if ( !$lb->isEmpty() ) {
			$lb->setCaller( __METHOD__ );
			$lb->execute();
		}
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
			return new Result( $this->result, $current, $this->interwikiPrefix );
		}
		return false;
	}

	public function addInterwikiResults( $res, $type, $interwiki ) {
		$this->interwikiResults[$type][$interwiki] = $res;
	}

	public function getInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return isset($this->interwikiResults[$type]) ? $this->interwikiResults[$type] : array();
	}

	public function hasInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return isset($this->interwikiResults[$type]);
	}

	public function searchContainedSyntax() {
		return $this->searchContainedSyntax;
	}

	public function setRewrittenQuery($newQuery, $newQuerySnippet=null) {
		$this->rewrittenQuery = $newQuery;
		$this->rewrittenQuerySnippet = $newQuerySnippet ?: htmlspecialchars( $newQuery );
	}

	public function hasRewrittenQuery() {
		return $this->rewrittenQuery !== null;
	}

	public function getQueryAfterRewrite() {
		return $this->rewrittenQuery;
	}

	public function getQueryAfterRewriteSnippet() {
		return $this->rewrittenQuerySnippet;
	}
}
