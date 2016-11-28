<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;
use GeoData\GeoData;
use GeoData\Coord;
use GeoData\Globe;
use Title;

/**
 * Applies geo based features to the query.
 *
 * Two forms of geo based querying are provided: a filter that limits search
 * results to a geographic area and a boost that increases the score of
 * results within the geographic area. Supports specifying geo coordinates
 * either by providing a latitude and longitude, or a page title to source the
 * latitude and longitude from. All values can be prefixed with a radius in m
 * or km to apply. If not specified this defaults to 5km.
 *
 * Examples:
 *  neartitle:Shanghai
 *  neartitle:50km,Seoul
 *  nearcoord:1.2345,-5.4321
 *  nearcoord:17km,54.321,-12.345
 *  boost-neartitle:"San Francisco"
 *  boost-neartitle:50km,Kampala
 *  boost-nearcoord:-12.345,87.654
 *  boost-nearcoord:77km,34.567,76.543
 */
class GeoFeature extends SimpleKeywordFeature {
	// Default radius, in meters
	const DEFAULT_RADIUS = 5000;
	// Default globe
	const DEFAULT_GLOBE = 'earth';

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return ['boost-nearcoord', 'boost-neartitle', 'nearcoord', 'neartitle'];
	}

	/**
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped
	 * @param string $quotedValue The original value in the search string, including quotes if used
	 * @param bool $negated Is the search negated? Not used to generate the returned AbstractQuery,
	 *  that will be negated as necessary. Used for any other building/context necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		if ( !class_exists( GeoData::class ) ) {
			return [ null, false ];
		}

		if ( substr( $key, -5 ) === 'title' ) {
			list( $coord, $radius, $excludeDocId ) = $this->parseGeoNearbyTitle(
				$context->getConfig(),
				$value
			);
		} else {
			list( $coord, $radius ) = $this->parseGeoNearby( $value );
			$excludeDocId = '';
		}

		$filter = null;
		if ( $coord ) {
			$context->setSearchType( 'geo_' . $context->getSearchType() );
			if ( substr( $key, 0, 6 ) === 'boost-' ) {
				$context->addGeoBoost( $coord, $radius, $negated ? 0.1 : 1 );
			} else {
				$filter = self::createQuery( $coord, $radius, $excludeDocId );
			}
		}

		return [ $filter, false ];
	}

	/**
	 * radius, if provided, must have either m or km suffix. Valid formats:
	 *   <title>
	 *   <radius>,<title>
	 *
	 * @param SearchConfig $config the Cirrus config object
	 * @param string $text user input to parse
	 * @return array Three member array with Coordinate object, integer radius
	 *  in meters, and page id to exclude from results.. When invalid the
	 *  Coordinate returned will be null.
	 */
	public function parseGeoNearbyTitle( SearchConfig $config, $text ) {
		$title = Title::newFromText( $text );
		if ( $title && $title->exists() ) {
			// Default radius if not provided: 5km
			$radius = self::DEFAULT_RADIUS;
		} else {
			// If the provided value is not a title try to extract a radius prefix
			// from the beginning. If $text has a valid radius prefix see if the
			// remaining text is a valid title to use.
			$pieces = explode( ',', $text, 2 );
			if ( count( $pieces ) !== 2 ) {
				return [ null, 0, '' ];
			}
			$radius = $this->parseDistance( $pieces[0] );
			if ( $radius === null ) {
				return [ null, 0, '' ];
			}
			$title = Title::newFromText( $pieces[1] );
			if ( !$title || !$title->exists() ) {
				return [ null, 0, '' ];
			}
		}

		$coord = GeoData::getPageCoordinates( $title );
		if ( !$coord ) {
			return [ null, 0, '' ];
		}

		return [ $coord, $radius, $config->makeId( $title->getArticleID() ) ];
	}

	/**
	 * radius, if provided, must have either m or km suffix. Latitude and longitude
	 * must be floats in the domain of [-90:90] for latitude and [-180,180] for
	 * longitude. Valid formats:
	 *   <lat>,<lon>
	 *   <radius>,<lat>,<lon>
	 *
	 * @param string $text
	 * @return array Two member array with Coordinate object, and integer radius
	 *  in meters. When invalid the Coordinate returned will be null.
	 */
	public function parseGeoNearby( $text ) {
		$pieces = explode( ',', $text, 3 );
		// Default radius if not provided: 5km
		$radius = self::DEFAULT_RADIUS;
		if ( count( $pieces ) === 3 ) {
			$radius = $this->parseDistance( $pieces[0] );
			if ( $radius === null ) {
				return [ null, 0 ];
			}
			$lat = $pieces[1];
			$lon = $pieces[2];
		} elseif ( count( $pieces ) === 2 ) {
			$lat = $pieces[0];
			$lon = $pieces[1];
		} else {
			return [ null, 0 ];
		}

		$globe = new Globe( self::DEFAULT_GLOBE );
		if ( !$globe->coordinatesAreValid( $lat, $lon ) ) {
			return [ null, 0 ];
		}

		return [
			new Coord( floatval( $lat ), floatval( $lon ), $globe->getName() ),
			$radius,
		];
	}

	/**
	 * @param string $distance
	 * @return int|null Parsed distance in meters, or null if unparsable
	 */
	public function parseDistance( $distance ) {
		if ( !preg_match( '/^(\d+)(m|km|mi|ft|yd)$/', $distance, $matches ) ) {
			return null;
		}

		$scale = [
			'm' => 1,
			'km' => 1000,
			// Supported non-SI units, and their conversions, sourced from
			// https://en.wikipedia.org/wiki/Unit_of_length#Imperial.2FUS
			'mi' => 1609.344,
			'ft' => 0.3048,
			'yd' => 0.9144,
		];

		return max( 10, (int) round( $matches[1] * $scale[$matches[2]] ) );
	}

	/**
	 * Create a filter for near: and neartitle: queries.
	 *
	 * @param Coord $coord
	 * @param int $radius Search radius in meters
	 * @param string $docIdToExclude Document id to exclude, or "" for no exclusions.
	 * @return AbstractQuery
	 */
	public static function createQuery( Coord $coord, $radius, $docIdToExclude = '' ) {
		$query = new \Elastica\Query\BoolQuery();
		$query->addFilter( new \Elastica\Query\Term( [ 'coordinates.globe' => $coord->globe ] ) );
		$query->addFilter( new \Elastica\Query\Term( [ 'coordinates.primary' => 1 ] ) );

		$distanceFilter = new \Elastica\Query\GeoDistance(
			'coordinates.coord',
			[ 'lat' => $coord->lat, 'lon' => $coord->lon ],
			$radius . 'm'
		);
		$distanceFilter->setOptimizeBbox( 'indexed' );
		$query->addFilter( $distanceFilter );

		if ( $docIdToExclude !== '' ) {
			$query->addMustNot( new \Elastica\Query\Term( [ '_id' => $docIdToExclude ] ) );
		}

		$nested = new \Elastica\Query\Nested();
		$nested->setPath( 'coordinates' )->setQuery( $query );

		return $nested;
	}

}
