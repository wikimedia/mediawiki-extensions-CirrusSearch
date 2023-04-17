<?php

declare( strict_types = 1 );
namespace CirrusSearch\Elastica;

use Elastica\Exception\ExceptionInterface as ElasticaExceptionInterface;
use Elastica\Query;
use Elastica\ResultSet;
use Elastica\Search;
use InvalidArgumentException;
use MediaWiki\Logger\LoggerFactory;
use RuntimeException;

class SearchAfter implements \Iterator {
	private const MAX_BACKOFF_SEC = 120;
	private const MICROSEC_PER_SEC = 1_000_000;
	/** @var Search */
	private $search;
	/** @var Query */
	private $baseQuery;
	/** @var ?ResultSet */
	private $currentResultSet;
	/** @var ?int */
	private $currentPage;
	/** @var float[] Sequence of second length backoffs to use for retries */
	private $backoff;
	/** @var array Initial value for search_after */
	private array $initialSearchAfter = [];

	/**
	 * @param Search $search
	 * @param int $numRetries The number of retries to perform on each iteration
	 * @param float $backoffFactor Scales the backoff duration, backoff calculated as
	 *   {backoffFactor} * 2^({retry} - 1) which gives, with no scaling, [0.5, 1, 2, 4, 8, ...]
	 */
	public function __construct( Search $search, int $numRetries = 12, float $backoffFactor = 1. ) {
		$this->search = $search;
		$this->baseQuery = clone $search->getQuery();
		if ( !$this->baseQuery->hasParam( 'sort' ) ) {
			throw new InvalidArgumentException( 'ScrollAfter query must have a sort' );
		}
		if ( $numRetries < 0 ) {
			throw new InvalidArgumentException( '$numRetries must be >= 0' );
		}
		$this->backoff = $this->calcBackoff( $numRetries, $backoffFactor );
	}

	public function current(): ResultSet {
		if ( $this->currentResultSet === null ) {
			throw new RuntimeException( 'Iterator is in an invalid state and must be rewound' );
		}
		return $this->currentResultSet;
	}

	public function key(): int {
		return $this->currentPage ?? 0;
	}

	public function next(): void {
		if ( $this->currentResultSet !== null ) {
			if ( count( $this->currentResultSet ) === 0 ) {
				return;
			}
			$lastHit = $this->currentResultSet[count( $this->currentResultSet ) - 1];
			$this->search->getQuery()->setParam( 'search_after', $lastHit->getSort() );
		} elseif ( $this->currentPage !== -1 ) {
			// iterator is in failed state
			return;
		}
		// ensure if runSearch throws the iterator becomes invalid
		$this->currentResultSet = null;
		$this->currentResultSet = $this->runSearch();
		$this->currentPage++;
	}

	public function initializeSearchAfter( array $searchAfter ): void {
		$this->initialSearchAfter = $searchAfter;
	}

	private function runSearch() {
		foreach ( $this->backoff as $backoffSec ) {
			try {
				return $this->search->search();
			} catch ( ElasticaExceptionInterface $e ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Exception thrown during SearchAfter iteration. Retrying in {backoffSec}s.",
					[
						'exception' => $e,
						'backoffSec' => $backoffSec,
					]
				);
				usleep( (int)( $backoffSec * self::MICROSEC_PER_SEC ) );
			}
		}
		// Final attempt after exhausting retries.
		return $this->search->search();
	}

	public function rewind(): void {
		// Use -1 so that on increment the first page is 0
		$this->currentPage = -1;
		$this->currentResultSet = null;
		$query = clone $this->baseQuery;
		if ( $this->initialSearchAfter ) {
			$query->setParam( 'search_after', $this->initialSearchAfter );
		}
		$this->search->setQuery( $query );
		// rewind performs the first query
		$this->next();
	}

	public function valid(): bool {
		return count( $this->currentResultSet ?? [] ) > 0;
	}

	private function calcBackoff( int $maxRetries, float $backoffFactor ): array {
		$backoff = [];
		for ( $retry = 0; $retry < $maxRetries; $retry++ ) {
			$backoff[$retry] = min( $backoffFactor * pow( 2, $retry - 1 ), self::MAX_BACKOFF_SEC );
		}
		return $backoff;
	}
}
