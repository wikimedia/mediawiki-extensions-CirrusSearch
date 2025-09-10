<?php

namespace CirrusSearch\Search;

use CirrusSearch\AlternativeIndices;
use CirrusSearch\Connection;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\SearchConfig;
use Elastica\Index;

class AlternativeIndex {
	private int $id;
	private string $type;
	private bool $use;
	private SearchConfig $hostConfig;
	private array $overrides;
	private ?SearchConfig $config = null;

	public function __construct( int $id, string $type, bool $use, SearchConfig $config, array $overrides ) {
		$this->id = $id;
		$this->type = $type;
		$this->use = $use;
		$this->hostConfig = $config;
		$this->overrides = $overrides;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getType(): string {
		return $this->type;
	}

	public function getConfig(): SearchConfig {
		if ( $this->config === null ) {
			$this->config = new HashSearchConfig( $this->overrides, [ HashSearchConfig::FLAG_INHERIT ], $this->hostConfig );
		}
		return $this->config;
	}

	public function getIndex( Connection $connection ): Index {
		switch ( $this->type ) {
			case AlternativeIndices::COMPLETION:
				$type = Connection::TITLE_SUGGEST_INDEX_SUFFIX;
				break;
			default:
				throw new \LogicException( "Unknown alternative index type {$this->type}" );
		}
		return $connection->getIndex( $this->getConfig()->get( 'CirrusSearchIndexBaseName' ), $type, false, true, $this->id );
	}

	/**
	 * Can this index be used at query time.
	 * @return bool
	 */
	public function isUse(): bool {
		return $this->use;
	}

	public function isInstanceIndex( string $indexName, Connection $connection ): bool {
		return str_starts_with( $indexName, $this->getIndex( $connection )->getName() . '_' );
	}
}
