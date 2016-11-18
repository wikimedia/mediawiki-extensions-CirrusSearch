<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\SearchConfig;
use GeoData\Coord;
use LoadBalancer;
use IDatabase;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * Test GeoFeature functions.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @group CirrusSearch
 */
class GeoFeatureTest extends CirrusTestCase {

	public function parseDistanceProvider() {
		return [
			'unknown units returns null' => [
				null,
				'100fur',
			],
			'gibberish returns null' => [
				null,
				'gibberish',
			],
			'no space allowed between numbers and units' => [
				null,
				'100 m',
			],
			'meters' => [
				100,
				'100m',
			],
			'kilometers' => [
				1000,
				'1km',
			],
			'yards' => [
				366,
				'400yd',
			],
			'one mile rounds down' => [
				1609,
				'1mi',
			],
			'two miles rounds up' => [
				'3219',
				'2mi',
			],
			'1000 feet rounds up' => [
				305,
				'1000ft',
			],
			'3000 feet rounds down' => [
				914,
				'3000ft',
			],
			'small requests are bounded' => [
				10,
				'1ft',
			],
			'allows large inputs' => [
				4321000,
				'4321km',
			],
		];
	}

	/**
	 * @dataProvider parseDistanceProvider
	 */
	public function testParseDistance( $expected, $distance ) {
		if ( class_exists( Coord::class ) ) {
			$feature = new GeoFeature();
			$this->assertEquals( $expected, $feature->parseDistance( $distance ) );
		} else {
			$this->markTestSkipped( 'GeoData extension must be installed' );
		}
	}

	public function parseGeoNearbyProvider() {
		return [
			'random input' => [
				[ null, 0 ],
				'gibberish'
			],
			'random input with comma' => [
				[ null, 0 ],
				'gibberish,42.42'
			],
			'random input with valid radius prefix' => [
				[ null, 0 ],
				'20km,42.42,invalid',
			],
			'valid coordinate, default radius' => [
				[
					[ 'lat' => 1.2345, 'lon' => 2.3456 ],
					5000,
				],
				'1.2345,2.3456',
			],
			'valid coordinate, specific radius in meters' => [
				[
					[ 'lat' => -5.4321, 'lon' => 42.345 ],
					4321,
				],
				'4321m,-5.4321,42.345',
			],
			'valid coordinate, specific radius in kilmeters' => [
				[
					[ 'lat' => 0, 'lon' => 42.345 ],
					7000,
				],
				'7km,0,42.345',
			],
			'out of bounds positive latitude' => [
				[ null, 0 ],
				'90.1,0'
			],
			'out of bounds negative latitude' => [
				[ null, 0 ],
				'-90.1,17',
			],
			'out of bounds positive longitude' => [
				[ null, 0 ],
				'49,180.1',
			],
			'out of bounds negative longitude' => [
				[ null, 0 ],
				'49,-180.001',
			],
			'valid coordinate with spaces' => [
				[
					[ 'lat' => 1.2345, 'lon' => 9.8765 ],
					5000
				],
				'1.2345, 9.8765'
			],
		];
	}

	/**
	 * @dataProvider parseGeoNearbyProvider
	 */
	public function testParseGeoNearby( $expected, $value ) {
		if ( class_exists( Coord::class ) ) {
			$feature = new GeoFeature;
			$result = $feature->parseGeoNearby( $value );
			if ( $result[0] instanceof Coord ) {
				$result[0] = [ 'lat' => $result[0]->lat, 'lon' => $result[0]->lon ];
			}
			$this->assertEquals( $expected, $result );
		} else {
			$this->markTestSkipped( 'GeoData extension must be installed' );
		}
	}

	public function parseGeoNearbyTitleProvider() {
		return [
			'basic page lookup' => [
				[
					[ 'lat' => 1.2345, 'lon' => 5.4321 ],
					5000,
					7654321,
				],
				'San Francisco'
			],
			'basic page lookup with radius in meters' => [
				[
					[ 'lat' => 1.2345, 'lon' => 5.4321 ],
					1234,
					7654321,
				],
				'1234m,San Francisco'
			],
			'basic page lookup with radius in kilometers' => [
				[
					[ 'lat' => 1.2345, 'lon' => 5.4321 ],
					2000,
					7654321,
				],
				'2km,San Francisco'
			],
			'basic page lookup with space between radius and name' => [
				[
					[ 'lat' => 1.2345, 'lon' => 5.4321 ],
					2000,
					7654321,
				],
				'2km, San Francisco'
			],
			'page with comma in name' => [
				[
					[ 'lat' => 1.2345, 'lon' => 5.4321 ],
					5000,
					1234567,
				],
				'Washington, D.C.'
			],
			'page with comma in name and radius in kilometers' => [
				[
					[ 'lat' => 1.2345, 'lon' => 5.4321 ],
					7000,
					1234567,
				],
				'7km,Washington, D.C.'
			],
			'unknown page lookup' => [
				[ null, 0, '' ],
				'Unknown Title',
			],
			'unknown page lookup with radius' => [
				[ null, 0, '' ],
				'4km, Unknown Title',
			],
		];
	}

	/**
	 * @dataProvider parseGeoNearbyTitleProvider
	 */
	public function testParseGeoNearbyTitle( $expected, $value ) {
		if ( ! class_exists( Coord::class ) ) {
			$this->markTestSkipped( 'GeoData extension must be installed' );
			return;
		}

		// Replace database with one that will return our fake coordinates if asked
		$db = $this->getMock( IDatabase::class );
		$db->expects( $this->any() )
			->method( 'select' )
			->with( 'geo_tags', $this->anything(), $this->anything(), $this->anything() )
			->will( $this->returnValue( [
				(object) [ 'gt_lat' => 1.2345, 'gt_lon' => 5.4321 ],
			] ) );
		// Tell LinkCache all titles not explicitly added don't exist
		$db->expects( $this->any() )
			->method( 'selectRow' )
			->with( 'page', $this->anything(), $this->anything(), $this->anything() )
			->will( $this->returnValue( false ) );
		// Inject mock database into a mock LoadBalancer
		$lb = $this->getMockBuilder( LoadBalancer::class )
			->disableOriginalConstructor()
			->getMock();
		$lb->expects( $this->any() )
			->method( 'getConnection' )
			->will( $this->returnValue( $db ) );
		$this->setService( 'DBLoadBalancer', $lb );

		// Inject fake San Francisco page into LinkCache so it "exists"
		MediaWikiServices::getInstance()->getLinkCache()
			->addGoodLinkObj( 7654321, Title::newFromText( 'San Francisco' ) );
		// Inject fake page with comma in it as well
		MediaWikiServices::getInstance()->getLinkCache()
			->addGoodLinkObj( 1234567, Title::newFromText( 'Washington, D.C.' ) );

		$config = $this->getMock( SearchConfig::class );
		$config->expects( $this->any() )
			->method( 'makeId' )
			->will( $this->returnCallback( function ( $id ) {
				return $id;
			} ) );

		// Finally run the test
		$feature = new GeoFeature;
		$result = $feature->parseGeoNearbyTitle( $config, $value );
		if ( $result[0] instanceof Coord ) {
			$result[0] = [ 'lat' => $result[0]->lat, 'lon' => $result[0]->lon ];
		}

		$this->assertEquals( $expected, $result );
	}
}
