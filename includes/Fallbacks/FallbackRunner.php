<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchMetricsProvider;
use CirrusSearch\Search\SearchQuery;
use Wikimedia\Assert\Assert;

class FallbackRunner implements SearchMetricsProvider {
	private static $NOOP_RUNNER = null;

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

	/**
	 * Noop fallback runner
	 * @return FallbackRunner
	 */
	public static function noopRunner(): FallbackRunner {
		self::$NOOP_RUNNER = self::$NOOP_RUNNER ?? new self( [] );
		return self::$NOOP_RUNNER;
	}

	public static function create( SearcherFactory $factory, SearchQuery $query ) {
		$fallbackMethods = [];
		if ( $query->isWithDYMSuggestion() ) {
			$fallbackMethods[] = PhraseSuggestFallbackMethod::build( $factory, $query );
		}
		if ( $query->getCrossSearchStrategy()->isCrossLanguageSearchSupported() ) {
			$fallbackMethods[] = LangDetectFallbackMethod::build( $factory, $query );
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
		$context = new FallbackRunnerContextImpl( $initialResult );
		foreach ( $this->fallbackMethods as $fallback ) {
			$position++;
			$score = $fallback->successApproximation( $context );
			if ( $score >= 1.0 ) {
				return $this->execute( $fallback, $context );
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
		foreach ( $methods as $fallbackArray ) {
			$fallback = $fallbackArray['method'];
			$context->setPreviousResultSet( $this->execute( $fallback, $context ) );
		}
		return $context->getPreviousResultSet();
	}

	/**
	 * @return array
	 */
	public function getElasticSuggesters(): array {
		$suggesters = [];
		foreach ( $this->fallbackMethods as $method ) {
			if ( $method instanceof ElasticSearchSuggestFallbackMethod ) {
				$suggestQueries = $method->getSuggestQueries();
				if ( $suggestQueries !== null ) {
					foreach ( $suggestQueries as $name => $suggestQ ) {
						Assert::precondition( !array_key_exists( $name, $suggesters ),
							get_class( $method ) . " is trying to add a suggester [$name] (duplicate)" );
						$suggesters[$name] = $suggestQ;
					}
				}
			}
		}
		return $suggesters;
	}

	/**
	 * @param FallbackMethod $fallbackMethod
	 * @return ResultSet
	 */
	private function execute( FallbackMethod $fallbackMethod, FallbackRunnerContext $context ) {
		$newResults = $fallbackMethod->rewrite( $context );
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
