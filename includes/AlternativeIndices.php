<?php

namespace CirrusSearch;

use CirrusSearch\Search\AlternativeIndex;
use MediaWiki\Config\ConfigException;
use Wikimedia\Assert\Assert;

class AlternativeIndices {
	public const COMPLETION = "completion";
	public const ALLOWED_TYPES = [ self::COMPLETION ];
	private SearchConfig $config;

	public static function build( SearchConfig $config ): AlternativeIndices {
		return new AlternativeIndices( $config );
	}

	private function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * @param string $type
	 * @return AlternativeIndex[]
	 */
	public function getAlternativeIndices( string $type ): array {
		Assert::parameter( in_array( $type, self::ALLOWED_TYPES, true ), '$type', "$type is not allowed" );
		$indices = $this->config->get( 'CirrusSearchAlternativeIndices' )[$type] ?? [];
		$altIndices = [];
		foreach ( $indices as $index ) {
			$id = $index["index_id"] ?? null;
			$use = $index["use"] ?? false;
			$overrides = $index["config_overrides"] ?? [];
			if ( $id === null ) {
				throw new ConfigException( "Missing [index_id] in CirrusSearchAlternativeIndices." );
			}
			if ( !self::isValidAltIndexId( $id ) ) {
				throw new ConfigException( "Expected a positive integer for [index_id] but got [$id]." );
			}
			$id = (int)$id;
			if ( array_key_exists( $id, $altIndices ) ) {
				throw new ConfigException( "Duplicated alternative index [$id]." );
			}
			if ( !is_array( $overrides ) ) {
				throw new ConfigException( "[config_overrides] for alternative index id [$id] must be an array." );
			}
			if ( !is_bool( $use ) ) {
				throw new ConfigException( "[use] for alternative index id [$id] must be a boolean." );
			}
			$altIndices[$id] = new AlternativeIndex( $id, $type, $use, $this->config, $overrides );
		}
		return $altIndices;
	}

	public function getAlternativeIndexById( string $type, int $id ): ?AlternativeIndex {
		return $this->getAlternativeIndices( $type )[$id] ?? null;
	}

	/**
	 * Check whether the provided $id is a valid alternative index id:
	 * - a positive integer represented as a string
	 * - a positive integer
	 * @param mixed $id
	 * @return bool true if $id is a valid alt index id and can safely be cast to int, false otherwise
	 */
	public static function isValidAltIndexId( mixed $id ): bool {
		if ( ( is_string( $id ) && ctype_digit( $id ) ) || is_int( $id ) ) {
			return (int)$id >= 0;
		}
		return false;
	}
}
