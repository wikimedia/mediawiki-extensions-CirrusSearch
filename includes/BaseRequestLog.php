<?php

namespace CirrusSearch;

use Wikimedia\Assert\Assert;
use Wikimedia\Stats\Metrics\CounterMetric;
use Wikimedia\Stats\Metrics\NullMetric;
use Wikimedia\Stats\Metrics\RunningTimer;
use Wikimedia\Stats\Metrics\TimingMetric;

abstract class BaseRequestLog implements RequestLog {
	/**
	 * @var string
	 */
	protected $description;

	/**
	 * @var string
	 */
	protected $queryType;

	/**
	 * @var array
	 */
	protected $extra;

	/**
	 * @var float|null The timestamp, with ms precision, the request started at
	 */
	protected $startTime;

	/**
	 * @var float|null The timestamp, with ms precision, the request ended at
	 */
	protected $endTime;

	private ?string $poolCounterType = null;
	private ?string $poolCounterKey = null;
	private RunningTimer|NullMetric|null $poolCounterAcquireTimeMetric = null;
	private ?string $poolCounterError = null;
	private array $metricLabels;
	private RunningTimer|NullMetric|null $requestTimeMetric = null;

	/**
	 * @param string $description
	 * @param string $queryType
	 * @param array $extra
	 * @param array $metricLabels
	 */
	public function __construct( $description, $queryType, array $extra = [], array $metricLabels = [] ) {
		$this->description = $description;
		$this->queryType = $queryType;
		$this->extra = $extra;
		$extraLabels = array_diff_key( $metricLabels, self::METRIC_LABELS_DEFAULT );
		Assert::precondition( $extraLabels === [],
			"must not provide extra metric labels [" . implode( ',', array_keys( $extraLabels ) ) . "]" );
		$this->metricLabels = $metricLabels + self::METRIC_LABELS_DEFAULT;
	}

	public function start() {
		$this->startTime = microtime( true );
		$stats = Util::getStatsFactory();
		$this->requestTimeMetric = $this->commonMetricsLabels( $stats->getTiming( "request_time_seconds" ) )
			->setLabel( "type", $this->getQueryType() ) // TODO: remove duplicate label
			->start();
	}

	public function poolCounterStartAcquire( string $type, string $key ): void {
		$this->poolCounterType = $type;
		$this->poolCounterKey = $key;
		$this->poolCounterAcquireTimeMetric = $this->commonMetricsLabels( Util::getStatsFactory()->getTiming( "pool_counter_seconds" ) )
			// Should match the 'pool' label from the request_time_seconds metric
			->setLabel( "type", Util::poolCounterLabel( $type ) ) // TODO: remove duplicate label
			->setLabel( "pool_counter_key", $key )
			->start();
	}

	public function poolCounterEndAcquire( ?string $error = null ): void {
		Assert::precondition( $this->poolCounterAcquireTimeMetric !== null, 'poolCounterAcquireTimeMetric must be started' );

		$this->poolCounterAcquireTimeMetric
			->setLabel( "status", $error === null ? "success" : "failure" )
			->stop();
		$this->poolCounterAcquireTimeMetric = null;
		$this->poolCounterError = $error;
	}

	public function finish( string $clusterName, ?string $errorType, bool $partial ) {
		$this->endTime = microtime( true );
		$this->requestTimeMetric
			->setLabel( "search_cluster", $clusterName )
			->setLabel( "error_type", $errorType ?: "none" )
			->setLabel( "partial_results", $partial ? "true" : "false" )
			->stop();

		if ( $errorType ) {
			$this->commonMetricsLabels( Util::getStatsFactory()->getCounter( "backend_failures_total" ) )
				->setLabel( "search_cluster", $clusterName )
				->setLabel( "type", $errorType )
				->increment();
		}
		if ( $partial ) {
			$this->commonMetricsLabels( Util::getStatsFactory()->getCounter( "partial_results_total" ) )
				->setLabel( "search_cluster", $clusterName )
				->setLabel( "type", $this->getQueryType() ) // TODO: remove duplicate label
				->increment();
		}
	}

	/**
	 * Common labels we want across a set of time series:
	 * - query_type
	 * - pool
	 * - metricLabels (defaults are in RequestLog::METRIC_LABELS_DEFAULT)
	 * @template T of CounterMetric|TimingMetric|RunningTimer|NullMetric
	 * @param T $metric
	 * @return T
	 */
	private function commonMetricsLabels(
		CounterMetric|TimingMetric|RunningTimer|NullMetric $metric
	): CounterMetric|TimingMetric|RunningTimer|NullMetric {
		$metric = $metric
			->setLabel( "query_type", $this->getQueryType() )
			->setLabel( "pool", $this->getPoolCounterType() ? Util::poolCounterLabel( $this->getPoolCounterType() ) : 'none' );
		// Do not call setLabels here because unlike others RunningTimer does not merge on setLabels...
		foreach ( $this->metricLabels as $label => $value ) {
			$metric = $metric->setLabel( $label, $value );
		}
		return $metric;
	}

	/**
	 * @return int|null
	 */
	public function getTookMs() {
		if ( $this->startTime && $this->endTime ) {
			return intval( 1000 * ( $this->endTime - $this->startTime ) );
		} else {
			return null;
		}
	}

	/**
	 * @return string
	 */
	public function getDescription() {
		return $this->description;
	}

	/**
	 * @return string
	 */
	public function getQueryType() {
		return $this->queryType;
	}

	/**
	 * Perform a quick and dirty replacement for $this->description
	 * when it's not going through monolog. It replaces {foo} with
	 * the value of foo
	 *
	 * @return string
	 */
	public function formatDescription() {
		$pairs = [];
		// @todo inefficient, getLogVariables may be doing work
		// that gets done multiple times.
		foreach ( $this->getLogVariables() as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$pairs['{' . $key . '}'] = $value;
			}
		}
		return strtr( $this->description, $pairs );
	}

	/**
	 * @inheritDoc
	 */
	public function getPoolCounterKey(): ?string {
		return $this->poolCounterKey;
	}

	/**
	 * @inheritDoc
	 */
	public function getPoolCounterType(): ?string {
		return $this->poolCounterType;
	}

	/**
	 * @inheritDoc
	 */
	public function getPoolCounterError(): ?string {
		return $this->poolCounterError;
	}
}
