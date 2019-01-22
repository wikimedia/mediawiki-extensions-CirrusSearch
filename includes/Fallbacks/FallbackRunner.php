<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchMetricsProvider;
use CirrusSearch\Search\SearchQuery;

class FallbackRunner implements SearchMetricsProvider {

	/**
	 * @var FallbackMethod[]
	 */
	private $fallbackMethods;

	/**
	 * @var array
	 */
	private $searchMetrics = [];

	/**
	 * @param FallbackMethod[] $fallbackMethods
	 */
	public function __construct( array $fallbackMethods ) {
		$this->fallbackMethods = $fallbackMethods;
	}

	public static function create( SearcherFactory $factory, SearchQuery $query, \WebRequest $request ) {
		$fallbackMethods = [];
		if ( $query->isWithDYMSuggestion() ) {
			$fallbackMethods[] = PhraseSuggestFallbackMethod::build( $factory, $query, $request );
		}
		if ( $query->getCrossSearchStrategy()->isCrossLanguageSearchSupported() ) {
			$fallbackMethods[] = LangDetectFallbackMethod::build( $factory, $query, $request );
		}
		return new self( $fallbackMethods );
	}
	/**
	 * @param ResultSet $initialResult
	 * @return ResultSet
	 */
	public function run( ResultSet $initialResult ) {
		$methods = [];
		$position = 0;
		foreach ( $this->fallbackMethods as $fallback ) {
			$position++;
			$score = $fallback->successApproximation( $initialResult );
			if ( $score >= 1.0 ) {
				return $this->execute( $fallback, $initialResult, $initialResult );
			}
			if ( $score <= 0 ) {
				continue;
			}
			$methods[] = [
				'method' => $fallback,
				'score' => $score,
				'position' => $position
			];
		}

		usort( $methods, function ( $a, $b ) {
			return $b['score'] <=> $a['score'] ?: $a['position'] <=> $b['position'];
		} );
		$previousResults = $initialResult;
		foreach ( $methods as $fallbackArray ) {
			$fallback = $fallbackArray['method'];
			$previousResults = $this->execute( $fallback, $previousResults, $initialResult );
		}
		return $previousResults;
	}

	/**
	 * @param FallbackMethod $fallbackMethod
	 * @param ResultSet $previous
	 * @param ResultSet $initial
	 * @return ResultSet
	 */
	private function execute( FallbackMethod $fallbackMethod, ResultSet $previous, ResultSet $initial ) {
		$newResults = $fallbackMethod->rewrite( $initial, $previous );
		if ( $fallbackMethod instanceof SearchMetricsProvider ) {
			$this->searchMetrics += $fallbackMethod->getMetrics() ?? [];
		}
		return $newResults;
	}

	/**
	 * @return array
	 */
	public function getMetrics() {
		return $this->searchMetrics;
	}
}
