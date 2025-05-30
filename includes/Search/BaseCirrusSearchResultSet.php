<?php

namespace CirrusSearch\Search;

use BaseSearchResultSet;
use HtmlArmor;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SearchResult;
use SearchResultSetTrait;
use Wikimedia\Assert\Assert;

/**
 * Base class to represent a CirrusSearchResultSet
 * Extensions willing to feed Cirrus with a CirrusSearchResultSet must extend this class.
 */
abstract class BaseCirrusSearchResultSet extends BaseSearchResultSet implements CirrusSearchResultSet {
	use SearchResultSetTrait;

	/** @var bool */
	private $hasMoreResults = false;

	/**
	 * @var CirrusSearchResult[]|null
	 */
	private $results;

	/**
	 * @var string|null
	 */
	private $suggestionQuery;

	/**
	 * @var HtmlArmor|string|null
	 */
	private $suggestionSnippet;

	/**
	 * @var array<int,array<string,CirrusSearchResultSet>>
	 */
	private $interwikiResults = [];

	/**
	 * @var string|null
	 */
	private $rewrittenQuery;

	/**
	 * @var HtmlArmor|string|null
	 */
	private $rewrittenQuerySnippet;

	/**
	 * @var TitleHelper
	 */
	private $titleHelper;

	/**
	 * @param \Elastica\Result $result Result from search engine
	 * @return CirrusSearchResult|null Elasticsearch result transformed into mediawiki
	 *  search result object.
	 */
	abstract protected function transformOneResult( \Elastica\Result $result );

	/**
	 * @return bool True when there are more pages of search results available.
	 */
	final public function hasMoreResults() {
		return $this->hasMoreResults;
	}

	/**
	 * @param string $suggestionQuery
	 * @param HtmlArmor|string|null $suggestionSnippet
	 */
	final public function setSuggestionQuery( string $suggestionQuery, $suggestionSnippet = null ) {
		$this->suggestionQuery = $suggestionQuery;
		$this->suggestionSnippet = $suggestionSnippet ?? $suggestionQuery;
	}

	/**
	 * Loads the result set into the mediawiki LinkCache via a
	 * batch query. By pre-caching this we ensure methods such as
	 * Result::isMissingRevision() don't trigger a query for each and
	 * every search result.
	 *
	 * @param \Elastica\ResultSet $resultSet Result set from which the titles come
	 */
	private function preCacheContainedTitles( \Elastica\ResultSet $resultSet ) {
		// We can only pull in information about the local wiki
		$lb = MediaWikiServices::getInstance()->getLinkBatchFactory()->newLinkBatch();
		foreach ( $resultSet->getResults() as $result ) {
			if ( !$this->getTitleHelper()->isExternal( $result )
				&& isset( $result->namespace )
				&& isset( $result->title )
			) {
				$lb->add( $result->namespace, $result->title );
			}
		}
		if ( !$lb->isEmpty() ) {
			$lb->setCaller( __METHOD__ );
			$lb->execute();
		}
	}

	/**
	 * @param bool $searchContainedSyntax
	 * @return CirrusSearchResultSet an empty result set
	 */
	final public static function emptyResultSet( $searchContainedSyntax = false ) {
		return new EmptySearchResultSet( $searchContainedSyntax );
	}

	/**
	 * @param int $limit Shrink result set to $limit and flag
	 *  if more results are available.
	 */
	final public function shrink( $limit ) {
		if ( $this->count() > $limit ) {
			Assert::precondition( $this->results !== null, "results not initialized" );
			$this->results = array_slice( $this->results, 0, $limit );
			$this->hasMoreResults = true;
		}
	}

	/**
	 * @return CirrusSearchResult[]|SearchResult[]
	 */
	final public function extractResults() {
		if ( $this->results === null ) {
			$this->results = [];
			$elasticaResults = $this->getElasticaResultSet();
			if ( $elasticaResults !== null ) {
				$this->preCacheContainedTitles( $elasticaResults );
				foreach ( $elasticaResults->getResults() as $result ) {
					$transformed = $this->transformOneResult( $result );
					if ( $transformed !== null ) {
						$this->augmentResult( $transformed );
						$this->results[] = $transformed;
					}
				}
			}
		}
		return $this->results;
	}

	/**
	 * Extract all the titles in the result set.
	 * @return Title[]
	 */
	final public function extractTitles() {
		return array_map(
			static function ( SearchResult $result ) {
				return $result->getTitle();
			},
			$this->extractResults() );
	}

	/**
	 * @param CirrusSearchResultSet $res
	 * @param int $type One of the ISearchResultSet::…_RESULTS constants
	 * @param string $interwiki
	 */
	final public function addInterwikiResults( CirrusSearchResultSet $res, $type, $interwiki ) {
		$this->interwikiResults[$type][$interwiki] = $res;
	}

	/**
	 * @param int $type One of the ISearchResultSet::…_RESULTS constants
	 * @return \ISearchResultSet[]
	 */
	final public function getInterwikiResults( $type = self::SECONDARY_RESULTS ) {
		return $this->interwikiResults[$type] ?? [];
	}

	/**
	 * @param int $type One of the ISearchResultSet::…_RESULTS constants
	 * @return bool
	 */
	final public function hasInterwikiResults( $type = self::SECONDARY_RESULTS ): bool {
		return (bool)( $this->interwikiResults[$type] ?? [] );
	}

	/**
	 * @param string $newQuery
	 * @param HtmlArmor|string|null $newQuerySnippet
	 */
	final public function setRewrittenQuery( string $newQuery, $newQuerySnippet = null ) {
		$this->rewrittenQuery = $newQuery;
		$this->rewrittenQuerySnippet = $newQuerySnippet ?? $newQuery;
	}

	/**
	 * @return bool
	 */
	final public function hasRewrittenQuery() {
		return $this->rewrittenQuery !== null;
	}

	/**
	 * @return string|null
	 */
	final public function getQueryAfterRewrite() {
		return $this->rewrittenQuery;
	}

	/**
	 * @return HtmlArmor|string|null
	 */
	final public function getQueryAfterRewriteSnippet() {
		return $this->rewrittenQuerySnippet;
	}

	/**
	 * @return bool
	 */
	final public function hasSuggestion() {
		return $this->suggestionQuery !== null;
	}

	/**
	 * @return string|null
	 */
	final public function getSuggestionQuery() {
		return $this->suggestionQuery;
	}

	/**
	 * @return string|null
	 */
	final public function getSuggestionSnippet() {
		return $this->suggestionSnippet;
	}

	/**
	 * Count elements of an object
	 * @link https://php.net/manual/en/countable.count.php
	 * @return int The custom count as an integer.
	 * @since 5.1.0
	 */
	final public function count(): int {
		return count( $this->extractResults() );
	}

	/**
	 * @return int
	 */
	final public function numRows() {
		return $this->count();
	}

	/**
	 * Some search modes return a total hit count for the query
	 * in the entire article database. This may include pages
	 * in namespaces that would not be matched on the given
	 * settings.
	 *
	 * Return null if no total hits number is supported.
	 *
	 * @return int|null
	 */
	final public function getTotalHits() {
		$elasticaResultSet = $this->getElasticaResultSet();
		if ( $elasticaResultSet !== null ) {
			return $elasticaResultSet->getTotalHits();
		}
		return 0;
	}

	/**
	 * @inheritDoc
	 */
	public function isApproximateTotalHits(): bool {
		$elasticaResultSet = $this->getElasticaResultSet();
		if ( $elasticaResultSet !== null ) {
			return $elasticaResultSet->getTotalHitsRelation() !== 'eq';
		}
		return false;
	}

	/**
	 * @return \Elastica\Response|null
	 */
	final public function getElasticResponse() {
		$elasticaResultSet = $this->getElasticaResultSet();
		return $elasticaResultSet != null ? $elasticaResultSet->getResponse() : null;
	}

	/**
	 * Useful to inject your own TitleHelper during tests
	 */
	protected function getTitleHelper(): TitleHelper {
		if ( $this->titleHelper === null ) {
			$this->titleHelper = new TitleHelper();
		}
		return $this->titleHelper;
	}
}
