<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\InterwikiResolver;
use CirrusSearch\Parser\NamespacePrefixParser;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\CirrusSearchResultSet;
use CirrusSearch\Search\MSearchRequests;
use CirrusSearch\Search\MSearchResponses;
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

	/**
	 * @param SearchQuery $query
	 * @param InterwikiResolver $interwikiResolver
	 * @param string $profileContext
	 * @param array $profileContextParam
	 * @return FallbackRunner
	 */
	public static function create(
		SearchQuery $query,
		InterwikiResolver $interwikiResolver,
		$profileContext = SearchProfileService::CONTEXT_DEFAULT,
		$profileContextParam = []
	): FallbackRunner {
		$profileService = $query->getSearchConfig()->getProfileService();
		if ( !$profileService->supportsContext( SearchProfileService::FALLBACKS, $profileContext ) ) {
			// This component is optional and we simply avoid building it if the $profileContext does
			// not define any defaults for it.
			return self::noopRunner();
		}
		return self::createFromProfile(
			$query,
			$profileService->loadProfile( SearchProfileService::FALLBACKS, $profileContext, null, $profileContextParam ),
			$interwikiResolver
		);
	}

	/**
	 * @param SearchQuery $query
	 * @param array $profile
	 * @param InterwikiResolver $interwikiResolver
	 * @return FallbackRunner
	 */
	private static function createFromProfile( SearchQuery $query, array $profile, InterwikiResolver $interwikiResolver ): FallbackRunner {
		$fallbackMethods = [];
		$methodDefs = $profile['methods'] ?? [];
		foreach ( $methodDefs as $methodDef ) {
			if ( !isset( $methodDef['class'] ) ) {
				throw new SearchProfileException( "Invalid FallbackMethod: missing 'class' definition in profile" );
			}
			$clazz = $methodDef['class'];
			$params = $methodDef['params'] ?? [];
			if ( !class_exists( $clazz ) ) {
				throw new SearchProfileException( "Invalid FallbackMethod: unknown class $clazz" );
			}
			if ( !is_subclass_of( $clazz, FallbackMethod::class ) ) {
				throw new SearchProfileException( "Invalid FallbackMethod: $clazz must implement " . FallbackMethod::class );
			}
			$method = call_user_func( [ $clazz, 'build' ], $query, $params, $interwikiResolver );
			if ( $method !== null ) {
				$fallbackMethods[] = $method;
			}
		}
		return new self( $fallbackMethods );
	}

	/**
	 * @param SearcherFactory $factory
	 * @param CirrusSearchResultSet $initialResult
	 * @param MSearchResponses $responses
	 * @param NamespacePrefixParser $namespacePrefixParser
	 * @return CirrusSearchResultSet
	 */
	public function run(
		SearcherFactory $factory,
		CirrusSearchResultSet $initialResult,
		MSearchResponses $responses,
		NamespacePrefixParser $namespacePrefixParser
	) {
		$methods = [];
		$position = 0;
		$context = new FallbackRunnerContextImpl( $initialResult, $factory, $namespacePrefixParser );
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
	 * @return CirrusSearchResultSet
	 */
	private function execute( FallbackMethod $fallbackMethod, FallbackRunnerContext $context ): CirrusSearchResultSet {
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
