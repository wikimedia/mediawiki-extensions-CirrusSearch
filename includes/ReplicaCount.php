<?php

namespace CirrusSearch;

use RuntimeException;

/**
 * The number of replicas an index is expected to have.
 *
 * Elasticsearch accepts this in two different shapes: a fixed
 * number_of_replicas, or an auto_expand_replicas range that it re-evaluates as
 * nodes join and leave the cluster. $wgCirrusSearchReplicas accepts either
 * form, but the cluster rejects a bare number in auto_expand_replicas, so which
 * setting we send depends on how the wiki was configured.
 */
class ReplicaCount {
	/**
	 * Value of $wgCirrusSearchReplicas, and of index.auto_expand_replicas, that
	 * turns auto expansion off.
	 */
	private const DISABLED = 'false';

	/** Shape of an auto_expand_replicas range, such as '0-2' or '0-all'. */
	private const RANGE_REGEX = '/^\d+-(\d+|all)$/';

	/** @var int|null Fixed number of replicas, null when auto expanding or disabled */
	private ?int $fixedCount;

	/** @var string|null auto_expand_replicas range, null when fixed or disabled */
	private ?string $autoExpandRange;

	private function __construct( ?int $fixedCount, ?string $autoExpandRange ) {
		$this->fixedCount = $fixedCount;
		$this->autoExpandRange = $autoExpandRange;
	}

	/**
	 * @param int $count Exact number of replicas the index must have
	 * @return self
	 */
	public static function fixed( int $count ): self {
		if ( $count < 0 ) {
			throw new RuntimeException( "Number of replicas must not be negative, got $count" );
		}
		return new self( $count, null );
	}

	/**
	 * @param string $range Range elasticsearch may expand and contract within,
	 *  such as '0-2' or '0-all'.
	 * @return self
	 */
	public static function autoExpand( string $range ): self {
		if ( !preg_match( self::RANGE_REGEX, $range ) ) {
			throw new RuntimeException( "Not a valid replica range: $range" );
		}
		return new self( null, $range );
	}

	/**
	 * Replication left to the cluster defaults: auto expansion is turned off and
	 * no particular number of replicas is enforced.
	 *
	 * @return self
	 */
	public static function disabled(): self {
		return new self( null, null );
	}

	/**
	 * @param int|string|bool $value A value from $wgCirrusSearchReplicas
	 * @return self
	 */
	public static function fromConfigValue( $value ): self {
		if ( $value === false ) {
			return self::disabled();
		} elseif ( is_int( $value ) ) {
			return self::fixed( $value );
		} elseif ( is_string( $value ) ) {
			if ( $value === self::DISABLED ) {
				return self::disabled();
			} elseif ( preg_match( '/^\d+$/', $value ) ) {
				return self::fixed( (int)$value );
			} elseif ( preg_match( self::RANGE_REGEX, $value ) ) {
				return self::autoExpand( $value );
			}
		}
		$repr = is_scalar( $value ) ? var_export( $value, true ) : gettype( $value );
		throw new RuntimeException( "Invalid replica count: $repr. \$wgCirrusSearchReplicas must be " .
			"a number of replicas (2), a range for elasticsearch to expand within ('0-2' or " .
			"'0-all'), or 'false' to leave replication to the cluster." );
	}

	/**
	 * The number of replicas an existing index is currently configured for.
	 *
	 * @param array $indexSettings Index settings as reported by the cluster,
	 *  see \Elastica\Index\Settings::get()
	 * @return self
	 */
	public static function fromIndexSettings( array $indexSettings ): self {
		$autoExpand = $indexSettings['auto_expand_replicas'] ?? self::DISABLED;
		if ( $autoExpand !== self::DISABLED && $autoExpand !== false ) {
			return self::autoExpand( (string)$autoExpand );
		} elseif ( isset( $indexSettings['number_of_replicas'] ) ) {
			return self::fixed( (int)$indexSettings['number_of_replicas'] );
		}
		return self::disabled();
	}

	/**
	 * Settings to include when creating an index. Only the setting we actually
	 * use is provided, the other keeps its elasticsearch default.
	 *
	 * @return array
	 */
	public function toCreateSettings(): array {
		if ( $this->fixedCount !== null ) {
			return [ 'number_of_replicas' => $this->fixedCount ];
		}
		return [ 'auto_expand_replicas' => $this->autoExpandRange ?? self::DISABLED ];
	}

	/**
	 * Settings to apply to an existing index. Unlike creation this has to turn
	 * off auto expansion when a fixed count is wanted, as an auto_expand_replicas
	 * left over from a previous configuration would override number_of_replicas.
	 *
	 * @return array
	 */
	public function toUpdateSettings(): array {
		if ( $this->fixedCount !== null ) {
			return [
				'auto_expand_replicas' => self::DISABLED,
				'number_of_replicas' => $this->fixedCount,
			];
		}
		// A range takes precedence over number_of_replicas, so there is nothing
		// to undo in the other direction.
		return [ 'auto_expand_replicas' => $this->autoExpandRange ?? self::DISABLED ];
	}

	/**
	 * @param array $indexSettings Index settings as reported by the cluster,
	 *  see \Elastica\Index\Settings::get()
	 * @return bool True when the index already has this number of replicas
	 */
	public function matchesIndexSettings( array $indexSettings ): bool {
		$actual = self::fromIndexSettings( $indexSettings );
		if ( $this->fixedCount === null && $this->autoExpandRange === null ) {
			// Replication is left to the cluster, all we ask is that auto
			// expansion is not deciding it for us.
			return $actual->autoExpandRange === null;
		}
		return $this->equals( $actual );
	}

	public function equals( ReplicaCount $other ): bool {
		return $this->fixedCount === $other->fixedCount
			&& $this->autoExpandRange === $other->autoExpandRange;
	}

	public function __toString(): string {
		if ( $this->fixedCount !== null ) {
			return (string)$this->fixedCount;
		}
		return $this->autoExpandRange ?? self::DISABLED;
	}
}
