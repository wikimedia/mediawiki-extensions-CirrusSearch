<?php

namespace CirrusSearch\Extra\MultiList;

/**
 * Special {@link MultiListItem} representing a weighted tag.
 *
 * @see https://wikitech.wikimedia.org/wiki/Search/WeightedTags
 */
class MultiListWeightedTag extends MultiListItem {

	public const WEIGHT_DELIMITER = '|';

	private ?int $weight;

	/**
	 * @param string $prefix Prefix
	 * @param string $name Name
	 * @param int|null $weight Weight
	 */
	public function __construct( string $prefix, string $name, ?int $weight = null ) {
		parent::__construct( $prefix, $name );
		$this->weight = $weight;
	}

	public function __toString(): string {
		return parent::__toString() . ( isset( $this->weight ) ? self::WEIGHT_DELIMITER . $this->weight : '' );
	}

	public function getWeight(): ?int {
		return $this->weight;
	}

}
