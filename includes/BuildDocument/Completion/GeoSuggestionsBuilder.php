<?php

namespace CirrusSearch\BuildDocument\Completion;

/**
 * Extra builder that generates new fields with a geo context
 * when applicable.
 */
class GeoSuggestionsBuilder implements ExtraSuggestionsBuilder {
	/** @const string field name */
	const FIELD = 'coordinates';

	/**
	 * {@inheritDoc}
	 */
	public function getRequiredFields() {
		return [ self::FIELD ];
	}

	/**
	 * @param mixed[] $inputDoc
	 * @param string $suggestType (title or redirect)
	 * @param int $score
	 * @param \Elastica\Document $suggestDoc suggestion type (title or redirect)
	 * @param int $targetNamespace
	 */
	public function build( array $inputDoc, $suggestType, $score, \Elastica\Document $suggestDoc, $targetNamespace ) {
		$location = $this->findPrimaryCoordinates( $inputDoc );
		if ( $location !== null ) {
			$this->copyToGeoFST( $location, 'suggest', 'suggest-geo', $suggestDoc );
			$this->copyToGeoFST( $location, 'suggest-stop', 'suggest-stop-geo', $suggestDoc );
		}
	}

	/**
	 * Copy the field $from to $to and adds the geo context
	 * @param float[] $location
	 * @param string $from field name we copy from
	 * @param string $to field name we copy to
	 * @param \Elastica\Document $suggestDoc
	 */
	private function copyToGeoFST( $location, $from, $to, \Elastica\Document $suggestDoc ) {
		if ( $suggestDoc->has( $from ) ) {
			$field = $suggestDoc->get( $from );
			$field['context'] = ['location' => $location];
			$suggestDoc->set( $to, $field );
		}
	}

	/**
	 * Inspects the 'coordinates' index and return the first coordinates flagged as 'primary'
	 * or the first coordinates if no primaries are found.
	 *
	 * @param array $inputDoc the input doc
	 * @return float[]|null with 'lat' and 'lon' or null
	 */
	public function findPrimaryCoordinates( array $inputDoc ) {
		if ( !isset( $inputDoc[self::FIELD] ) || !is_array( $inputDoc[self::FIELD] ) ) {
			return null;
		}

		$first = null;
		foreach( $inputDoc[self::FIELD] as $coord ) {
			if ( isset( $coord['globe'] ) && $coord['globe'] == 'earth' && isset( $coord['coord'] ) ) {
				if ( $first === null ) {
					$first = $coord['coord'];
				}
				if ( isset( $coord['primary'] ) && $coord['primary'] ) {
					return $coord['coord'];
				}
			}
		}
		return $first;
	}
}
