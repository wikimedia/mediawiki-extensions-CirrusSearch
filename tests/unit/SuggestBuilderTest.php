<?php

namespace CirrusSearch;

use CirrusSearch\BuildDocument\SuggestBuilder;
use CirrusSearch\BuildDocument\SuggestScoringMethodFactory;

/**
 * test suggest builder.
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
 */
class SuggestBuilderTest extends \MediaWikiTestCase {
	public function testEinstein() {
		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks', 1 ) );
		$score = 10;
		$redirScore = (int) ( $score * SuggestBuilder::REDIRECT_DISCOUNT );
		$doc = array(
			'title' => 'Albert Einstein',
			'redirect' => array(
				array( 'title' => "Albert Enstein", 'namespace' => 0 ),
				array( 'title' => "Albert Einsten", 'namespace' => 0 ),
				array( 'title' => 'Albert Einstine', 'namespace' => 0 ),
				array( 'title' => "Enstein", 'namespace' => 0 ),
				array( 'title' => "Einstein", 'namespace' => 0 ),
			),
			'incoming_links' => $score
		);
		$expected = array(
			array(
				'suggest' => array(
					'input' => array( 'Albert Einstein', 'Albert Enstein',
						'Albert Einsten', 'Albert Einstine' ),
					'output' => '1:t:Albert Einstein',
					'weight' => $score
				),
				'suggest-stop' => array(
					'input' => array( 'Albert Einstein', 'Albert Enstein',
						'Albert Einsten', 'Albert Einstine' ),
					'output' => '1:t:Albert Einstein',
					'weight' => $score
				)
			),
			array(
				'suggest' => array(
					'input' => array( 'Enstein', 'Einstein' ),
					'output' => '1:r',
					'weight' => $redirScore
				),
				'suggest-stop' => array(
					'input' => array( 'Enstein', 'Einstein' ),
					'output' => '1:r',
					'weight' => $redirScore
				)
			)
		);

		$suggestions = $builder->build( 1, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testUlm() {
		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks', 1 ) );
		$score = 10;
		$redirScore = (int) ( $score * SuggestBuilder::REDIRECT_DISCOUNT );
		$doc = array(
			'title' => 'Ulm',
			'redirect' => array(
				array( 'title' => 'UN/LOCODE:DEULM', 'namespace' => 0 ),
				array( 'title'=> 'Ulm, Germany', 'namespace' => 0 ),
				array( 'title' => "Ulm displaced persons camp", 'namespace' => 0 ),
				array( 'title' => "Söflingen", 'namespace' => 0 ),
			),
			'coordinates' => array(
				array(
					'coord' => array(
						'lat' => 48.3985,
						'lon' => 9.9918
					),
					'region' => "BW",
					'dim' => 10000,
					'name' => "",
					'primary' => true,
					'type' => "city",
					'globe' => "earth",
					'country' => "DE"
				)
			),
			'incoming_links' => $score
		);

		$expected = array(
			array(
				'suggest' => array(
					'input' => array( 'Ulm' ),
					'output' => '1:t:Ulm',
					'weight' => $score
				),
				'suggest-stop' => array(
					'input' => array( 'Ulm' ),
					'output' => '1:t:Ulm',
					'weight' => $score
				),
				'suggest-geo' => array(
					'input' => array( 'Ulm' ),
					'output' => '1:t:Ulm',
					'weight' => $score,
					'context' => array(
						'location' => array(
							'lat' => 48.3985,
							'lon' => 9.9918
						)
					)
				),
				'suggest-stop-geo' => array(
					'input' => array( 'Ulm' ),
					'output' => '1:t:Ulm',
					'weight' => $score,
					'context' => array(
						'location' => array(
							'lat' => 48.3985,
							'lon' => 9.9918
						)
					)
				)
			),
			array(
				'suggest' => array(
					'input' => array( 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ),
					'output' => '1:r',
					'weight' => $redirScore
				),
				'suggest-stop' => array(
					'input' => array( 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ),
					'output' => '1:r',
					'weight' => $redirScore
				),
				'suggest-geo' => array(
					'input' => array( 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ),
					'output' => '1:r',
					'weight' => $redirScore,
					'context' => array(
						'location' => array(
							'lat' => 48.3985,
							'lon' => 9.9918
						)
					)
				),
				'suggest-stop-geo' => array(
					'input' => array( 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ),
					'output' => '1:r',
					'weight' => $redirScore,
					'context' => array(
						'location' => array(
							'lat' => 48.3985,
							'lon' => 9.9918
						)
					)
				)
			)
		);
		$suggestions = $builder->build( 1, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testMultipleCoordinates() {
		$doc = array(
			'coordinates' => array(
				array(
					'coord' => array(
						'lat' => 0.70777777777778,
						'lon' => -50.089444444444
					),
					'region' => null,
					'dim' => 10000,
					'name' => "",
					'primary' => true,
					'type' => "river",
					'globe' => "earth",
					'country' => "BR"
				),
				array(
					'coord' => array(
						'lat' => -15.518055555556,
						'lon' => -71.765277777778
					),
					'region' => null,
					'dim' => 10000,
					'name' => "",
					'primary' => false,
					'type' => "river",
					'globe' => "earth",
					'country' => "BR"
				)
			)
		);

		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks', 1 ) );
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = array( 'lat' => 0.70777777777778, 'lon' => -50.089444444444 );
		$this->assertSame( $expected, $coord );

		$doc['coordinates'][1]['primary'] = true;
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = array( 'lat' => 0.70777777777778, 'lon' => -50.089444444444 );
		$this->assertSame( $expected, $coord, "With two primaries coord we choose the first one" );

		$doc['coordinates'][0]['primary'] = false;
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = array( 'lat' => -15.518055555556, 'lon' => -71.765277777778 );
		$this->assertSame( $expected, $coord, "Choose primary coord even if it's not the first one." );

		$doc['coordinates'][1]['primary'] = false;
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = array( 'lat' => 0.70777777777778, 'lon' => -50.089444444444 );
		$this->assertSame( $expected, $coord, "Choose first coord if there's no primary." );

		$doc['coordinates'][0]['primary'] = true;
		$doc['coordinates'][0]['globe'] = 'Magrathea';
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = array( 'lat' => -15.518055555556, 'lon' => -71.765277777778 );
		$this->assertSame( $expected, $coord, "Choose first coord on earth." );

		$doc['coordinates'][1]['globe'] = 'Magrathea';
		$coord = $builder->findPrimaryCoordinates( $doc );
		$this->assertNull( $coord, "No coord if none is on earth." );
	}
}
