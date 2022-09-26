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
	/** @var Search */
	private $search;
	/** @var Query */
	private $baseQuery;
	/** @var ?ResultSet */
	private $currentResultSet;
	/** @var ?int */
	private $currentPage;
	/** @var int The number of retries to perform on each iteration */
	private $numRetries;

	/**
	 * @param Search $search
	 * @param int $numRetries The number of retries to perform on each iteration
	 */
	public function __construct( Search $search, int $numRetries = 5 ) {
		$this->search = $search;
		$this->baseQuery = clone $search->getQuery();
		if ( !$this->baseQuery->hasParam( 'sort' ) ) {
			throw new InvalidArgumentException( 'ScrollAfter query must have a sort' );
		}
		$this->numRetries = $numRetries;
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

	private function runSearch() {
		$retry = 0;
		while ( true ) {
			try {
				return $this->search->search();
			} catch ( ElasticaExceptionInterface $e ) {
				if ( $retry >= $this->numRetries ) {
					throw $e;
				}
				$retry++;
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Exception thrown during SearchAfter iteration. Retrying.",
					[ 'exception' => $e ]
				);
			}
		}
	}

	public function rewind(): void {
		// Use -1 so that on increment the first page is 0
		$this->currentPage = -1;
		$this->currentResultSet = null;
		$this->search->setQuery( clone $this->baseQuery );
		// rewind performs the first query
		$this->next();
	}

	public function valid(): bool {
		return count( $this->currentResultSet ?? [] ) > 0;
	}
}
