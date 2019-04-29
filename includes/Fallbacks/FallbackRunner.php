<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\MSearchRequests;
use CirrusSearch\Search\MSearchResponses;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchMetricsProvider;
use CirrusSearch\Search\SearchQuery;
use Elastica\Client;
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
	 * @param MSearchResponses $responses
	 * @return ResultSet
	 */
	public function run( ResultSet $initialResult, MSearchResponses $responses ) {
		$methods = [];
		$position = 0;
		$context = new FallbackRunnerContextImpl( $initialResult );
		foreach ( $this->fallbackMethods as $fallback ) {
			$position++;
			$context->resetSuggestResponse();
			if ( $fallback instanceof ElasticSearchRequestFallbackMethod ) {
				$k = $this->msearchKey( $position );
				if ( $responses->hasResultsFor( $k ) ) {
					$context->setSuggestResponse( $responses->getResultSet( $this->msearchKey( $position ) ) );
				}
			}
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
			$context->resetSuggestResponse();
			if ( $fallback instanceof ElasticSearchRequestFallbackMethod ) {
				$context->setSuggestResponse( $responses->getResultSet( $this->msearchKey( $fallbackArray['position'] ) ) );
			}
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

	public function attachSearchRequests( MSearchRequests $requests, Client $client ) {
		$position = 0;
		foreach ( $this->fallbackMethods as $method ) {
			$position++;
			if ( $method instanceof ElasticSearchRequestFallbackMethod ) {
				$search = $method->getSearchRequest( $client );
				if ( $search !== null ) {
					$requests->addRequest(
						$this->msearchKey( $position ),
						$search
					);
				}
			}
		}
	}

	/**
	 * @param int $position
	 * @return string
	 */
	private function msearchKey( $position ) {
		return "fallback-$position";
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
