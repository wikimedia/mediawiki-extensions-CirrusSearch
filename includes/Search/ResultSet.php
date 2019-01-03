<?php

namespace CirrusSearch\Search;

use CirrusSearch\Searcher;
use LinkBatch;
use SearchResultSet;

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
	 * @var \Elastica\ResultSet
	 */
	private $result;

	/**
	 * @var int
	 */
	private $totalHits;

	/**
	 * @var string|null
	 */
	private $suggestionQuery;

	/**
	 * @var string|null
	 */
	private $suggestionSnippet;

	/**
	 * @var array
	 */
	private $interwikiResults = [];

	/**
	 * @var string|null
	 */
	private $rewrittenQuery;

	/**
	 * @var string|null
	 */
	private $rewrittenQuerySnippet;

	/**
	 * @param string[] $suggestPrefixes
	 * @param string[] $suggestSuffixes
	 * @param \Elastica\ResultSet $res
	 * @param bool $searchContainedSyntax
	 */
	public function __construct( array $suggestPrefixes, array $suggestSuffixes, \Elastica\ResultSet $res, $searchContainedSyntax ) {
		parent::__construct( $searchContainedSyntax );
		$this->result = $res;
		$this->totalHits = $res->getTotalHits();
		$this->preCacheContainedTitles( $this->result );
		$suggestion = $this->findSuggestion();
		// TODO: move all the DYM "suggestion" code to PhraseSuggestFallbackMethod (once we get rid of suggest[Prefixes/Suffixes])
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
	 * Copy object state into another object
	 *
	 * Copies the state of this object into another class
	 * (likely extended from this class). Used in place of a decorator
	 * because core does not expose an interface for this, and we cannot
	 * otherwise satisfy type constraints matching this class.
	 *
	 * @param ResultSet $other
	 */
	protected function copyTo( ResultSet $other ) {
		$other->result = $this->result;
		$other->totalHits = $this->totalHits;
		$other->suggestionQuery = $this->suggestionQuery;
		$other->suggestionSnippet = $this->suggestionSnippet;
		$other->interwikiResults = $this->interwikiResults;
		$other->rewrittenQuery = $this->rewrittenQuery;
		$other->rewrittenQuerySnippet = $this->rewrittenQuerySnippet;
	}

	/**
	 * @param int $threshold
	 * @return bool always false
	 * @deprecated always return false, moved to \CirrusSearch\Fallbacks\FallbackMethodTrait::resultsThreshold()
	 * @see \CirrusSearch\Fallbacks\FallbackMethodTrait::resultsThreshold()
	 */
	public function isQueryRewriteAllowed( $threshold = 1 ) {
		return false;
	}

	/**
	 * @return string|null
	 */
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
	 *
	 * @param string $suggestion suggestion from elasticsearch
	 * @return string $suggestion with html escaped _except_ highlighting pre and post tags
	 */
	private function escapeHighlightedSuggestion( $suggestion ) {
		return strtr( htmlspecialchars( $suggestion ), [
			Searcher::HIGHLIGHT_PRE_MARKER => Searcher::SUGGESTION_HIGHLIGHT_PRE,
			Searcher::HIGHLIGHT_POST_MARKER => Searcher::SUGGESTION_HIGHLIGHT_POST,
		] );
	}

	/**
	 * @return bool
	 */
	private function resultContainsFullyHighlightedMatch() {
		foreach ( $this->result->getResults() as $result ) {
			$highlights = $result->getHighlights();
			// If the whole string is highlighted then return true
			$regex = '/' . Searcher::HIGHLIGHT_PRE_MARKER . '.*?' . Searcher::HIGHLIGHT_POST_MARKER . '/';
			if ( isset( $highlights[ 'title' ] ) &&
					!trim( preg_replace( $regex, '', $highlights[ 'title' ][ 0 ] ) ) ) {
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
	 *
	 * @param \Elastica\ResultSet $resultSet Result set from which the titles come
	 */
	protected function preCacheContainedTitles( \Elastica\ResultSet $resultSet ) {
		// We can only pull in information about the local wiki
		$lb = new LinkBatch;
		foreach ( $resultSet->getResults() as $result ) {
			if ( !TitleHelper::isExternal( $result ) ) {
				$lb->add( $result->namespace, $result->title );
			}
		}
		if ( !$lb->isEmpty() ) {
			$lb->setCaller( __METHOD__ );
			$lb->execute();
		}
	}

	/**
	 * @return int
	 */
	public function getTotalHits() {
		return $this->totalHits;
	}

	/**
	 * @return bool
	 */
	public function hasSuggestion() {
		return $this->suggestionQuery !== null;
	}

	/**
	 * @return string|null
	 */
	public function getSuggestionQuery() {
		return $this->suggestionQuery;
	}

	/**
	 * @return string
	 */
	public function getSuggestionSnippet() {
		return $this->suggestionSnippet;
	}

	public function extractResults() {
		if ( $this->results === null ) {
			$this->results = [];
			foreach ( $this->result->getResults() as $result ) {
				$transformed = $this->transformOneResult( $result );
				$this->augmentResult( $transformed );
				$this->results[] = $transformed;
			}
		}
		return $this->results;
	}

	/**
	 * @param \Elastica\Result $result Result from search engine
	 * @return Result Elasticsearch result transformed into mediawiki
	 *  search result object.
	 */
	protected function transformOneResult( \Elastica\Result $result ) {
		return new Result( $this->result, $result );
	}

	/**
	 * @param ResultSet $res
	 * @param int $type One of SearchResultSet::* constants
	 * @param string $interwiki
	 */
	public function addInterwikiResults( ResultSet $res, $type, $interwiki ) {
		$this->interwikiResults[$type][$interwiki] = $res;
	}

	/**
	 * @param int $type
	 * @return SearchResultSet[]
	 */
	public function getInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return isset( $this->interwikiResults[$type] ) ? $this->interwikiResults[$type] : [];
	}

	/**
	 * @param int $type
	 * @return bool
	 */
	public function hasInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return isset( $this->interwikiResults[$type] );
	}

	/**
	 * @param string $newQuery
	 * @param string|null $newQuerySnippet
	 */
	public function setRewrittenQuery( $newQuery, $newQuerySnippet = null ) {
		$this->rewrittenQuery = $newQuery;
		$this->rewrittenQuerySnippet = $newQuerySnippet ?: htmlspecialchars( $newQuery );
	}

	/**
	 * @return bool
	 */
	public function hasRewrittenQuery() {
		return $this->rewrittenQuery !== null;
	}

	/**
	 * @return string|null
	 */
	public function getQueryAfterRewrite() {
		return $this->rewrittenQuery;
	}

	/**
	 * @return string|null
	 */
	public function getQueryAfterRewriteSnippet() {
		return $this->rewrittenQuerySnippet;
	}
}
