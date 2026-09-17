<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusSearch;
use CirrusSearch\Profile\SearchProfileService;
use Wikimedia\Assert\Assert;

/**
 * Semantic SearchQuery routing functionality which produces a constant
 * score when successful, 0.0 otherwise.
 * Inspects the debug options carried by the query being routed.
 */
class SemanticSearchQueryRoute implements SearchQueryRoute {
	private string $searchEngineEntryPoint;
	private array $namespaces;
	private float $score;

	/**
	 * @param string $searchEngineEntryPoint
	 * @param int[] $namespaces
	 * @param float $score
	 */
	public function __construct(
		string $searchEngineEntryPoint,
		array $namespaces,
		float $score
	) {
		$this->searchEngineEntryPoint = $searchEngineEntryPoint;
		$this->namespaces = $namespaces;
		$this->score = $score;
	}

	/**
	 * @param \CirrusSearch\Search\SearchQuery $query
	 * @return float
	 */
	public function score( \CirrusSearch\Search\SearchQuery $query ) {
		Assert::parameter( $query->getSearchEngineEntryPoint() === $this->searchEngineEntryPoint,
			'query',
			"must be {$this->searchEngineEntryPoint} but {$query->getSearchEngineEntryPoint()} given." );

		if ( !$query->getDebugOptions()->isCirrusSemanticSearch() ) {
			return self::REJECT_ROUTE;
		}

		if ( $this->namespaces !== [] ) {
			$qNs = $query->getNamespaces();
			if ( $qNs === [] ) {
				return self::REJECT_ROUTE;
			}
			if ( count( array_intersect( $this->namespaces, $qNs ) ) !== count( $qNs ) ) {
				return self::REJECT_ROUTE;
			}
		}

		// Semantic search does not support user-selected profiles currently, only autoselect.
		foreach ( $query->getForcedProfiles() as $profileName ) {
			if ( $profileName !== CirrusSearch::AUTOSELECT_PROFILE ) {
				return self::REJECT_ROUTE;
			}
		}

		return $this->score;
	}

	/**
	 * The entry point used in the search engine:
	 * - searchText
	 * - nearMatch
	 * - completionSearch
	 *
	 * @return string
	 */
	public function getSearchEngineEntryPoint(): string {
		return $this->searchEngineEntryPoint;
	}

	/**
	 * The SearchProfile context to use when this route is chosen.
	 *
	 * @return string
	 */
	public function getProfileContext(): string {
		return SearchProfileService::CONTEXT_SEMANTIC;
	}
}
