<?php

namespace CirrusSearch;

use Wikimedia\Assert\Assert;
use Wikimedia\Stats\Metrics\RunningTimer;

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
	private ?RunningTimer $poolCounterAcquireTimeMetric = null;
	private ?string $poolCounterError = null;

	/**
	 * @param string $description
	 * @param string $queryType
	 * @param array $extra
	 */
	public function __construct( $description, $queryType, array $extra = [] ) {
		$this->description = $description;
		$this->queryType = $queryType;
		$this->extra = $extra;
	}

	public function start() {
		$this->startTime = microtime( true );
	}

	public function poolCounterStartAcquire( string $type, string $key ): void {
		$this->poolCounterType = $type;
		$this->poolCounterKey = $key;
		$this->poolCounterAcquireTimeMetric = Util::getStatsFactory()->getTiming( "pool_counter_seconds" )
			->setLabel( "type", Util::poolCounterLabel( $type ) )
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

	public function finish() {
		$this->endTime = microtime( true );
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
