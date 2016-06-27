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
		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks' ) );
		$score = 10;
		$redirScore = (int) ( $score * SuggestBuilder::REDIRECT_DISCOUNT );
		$doc = [
			'title' => 'Albert Einstein',
			'namespace' => 0,
			'redirect' => [
				[ 'title' => "Albert Enstein", 'namespace' => 0 ],
				[ 'title' => "Albert Einsten", 'namespace' => 0 ],
				[ 'title' => 'Albert Einstine', 'namespace' => 0 ],
				[ 'title' => "Enstein", 'namespace' => 0 ],
				[ 'title' => "Einstein", 'namespace' => 0 ],
			],
			'incoming_links' => $score
		];
		$expected = [
			[
				'suggest' => [
					'input' => [ 'Albert Einstein', 'Albert Enstein',
						'Albert Einsten', 'Albert Einstine' ],
					'output' => '1:t:Albert Einstein',
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'Albert Einstein', 'Albert Enstein',
						'Albert Einsten', 'Albert Einstine' ],
					'output' => '1:t:Albert Einstein',
					'weight' => $score
				]
			],
			[
				'suggest' => [
					'input' => [ 'Enstein', 'Einstein' ],
					'output' => '1:r',
					'weight' => $redirScore
				],
				'suggest-stop' => [
					'input' => [ 'Enstein', 'Einstein' ],
					'output' => '1:r',
					'weight' => $redirScore
				]
			]
		];

		$suggestions = $this->buildSuggestions( $builder, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testEraq() {
		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks' ) );
		$score = 10;
		$redirScore = (int) ( $score * SuggestBuilder::REDIRECT_DISCOUNT );
		$doc = [
			'title' => 'Iraq',
			'namespace' => 0,
			'redirect' => [
				[ 'title' => "Eraq", 'namespace' => 0 ],
				[ 'title' => "Irak", 'namespace' => 0 ],
			],
			'incoming_links' => $score
		];

		$expected = [
			[
				'suggest' => [
					'input' => [ 'Iraq', 'Irak' ],
					'output' => '1:t:Iraq',
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'Iraq', 'Irak' ],
					'output' => '1:t:Iraq',
					'weight' => $score
				]
			],
			[
				'suggest' => [
					'input' => [ 'Eraq' ],
					'output' => '1:r',
					'weight' => $redirScore
				],
				'suggest-stop' => [
					'input' => [ 'Eraq' ],
					'output' => '1:r',
					'weight' => $redirScore
				]
			]
		];
		$suggestions = $this->buildSuggestions( $builder, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testCrossNSRedirects() {
		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks' ) );
		$score = 10;
		$doc = [
			'title' => 'Navigation',
			'namespace' => 12,
			'redirect' => [
				[ 'title' => 'WP:HN', 'namespace' => 0 ],
				[ 'title' => 'WP:NAV', 'namespace' => 0 ],
			],
			'incoming_links' => $score
		];

		$score = (int) (SuggestBuilder::CROSSNS_DISCOUNT * $score);

		$expected = [
			[
				'suggest' => [
					'input' => [ 'WP:HN' ],
					'output' => '0:t:WP:HN', // LinkBatch will set 0...
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'WP:HN' ],
					'output' => '0:t:WP:HN',
					'weight' => $score
				],
			],
			[
				'suggest' => [
					'input' => [ 'WP:NAV' ],
					'output' => '0:t:WP:NAV',
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'WP:NAV' ],
					'output' => '0:t:WP:NAV',
					'weight' => $score
				],
			]
		];
		$suggestions = $this->buildSuggestions( $builder, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testUlm() {
		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks' ) );
		$score = 10;
		$redirScore = (int) ( $score * SuggestBuilder::REDIRECT_DISCOUNT );
		$doc = [
			'title' => 'Ulm',
			'namespace' => 0,
			'redirect' => [
				[ 'title' => 'UN/LOCODE:DEULM', 'namespace' => 0 ],
				[ 'title'=> 'Ulm, Germany', 'namespace' => 0 ],
				[ 'title' => "Ulm displaced persons camp", 'namespace' => 0 ],
				[ 'title' => "Söflingen", 'namespace' => 0 ],
				[ 'title' => "Should be ignored", 'namespace' => 1 ],
			],
			'coordinates' => [
				[
					'coord' => [
						'lat' => 48.3985,
						'lon' => 9.9918
					],
					'region' => "BW",
					'dim' => 10000,
					'name' => "",
					'primary' => true,
					'type' => "city",
					'globe' => "earth",
					'country' => "DE"
				]
			],
			'incoming_links' => $score
		];

		$expected = [
			[
				'suggest' => [
					'input' => [ 'Ulm' ],
					'output' => '1:t:Ulm',
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'Ulm' ],
					'output' => '1:t:Ulm',
					'weight' => $score
				],
				'suggest-geo' => [
					'input' => [ 'Ulm' ],
					'output' => '1:t:Ulm',
					'weight' => $score,
					'context' => [
						'location' => [
							'lat' => 48.3985,
							'lon' => 9.9918
						]
					]
				],
				'suggest-stop-geo' => [
					'input' => [ 'Ulm' ],
					'output' => '1:t:Ulm',
					'weight' => $score,
					'context' => [
						'location' => [
							'lat' => 48.3985,
							'lon' => 9.9918
						]
					]
				]
			],
			[
				'suggest' => [
					'input' => [ 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ],
					'output' => '1:r',
					'weight' => $redirScore
				],
				'suggest-stop' => [
					'input' => [ 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ],
					'output' => '1:r',
					'weight' => $redirScore
				],
				'suggest-geo' => [
					'input' => [ 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ],
					'output' => '1:r',
					'weight' => $redirScore,
					'context' => [
						'location' => [
							'lat' => 48.3985,
							'lon' => 9.9918
						]
					]
				],
				'suggest-stop-geo' => [
					'input' => [ 'UN/LOCODE:DEULM', 'Ulm, Germany',
						'Ulm displaced persons camp', 'Söflingen' ],
					'output' => '1:r',
					'weight' => $redirScore,
					'context' => [
						'location' => [
							'lat' => 48.3985,
							'lon' => 9.9918
						]
					]
				]
			]
		];
		$suggestions = $this->buildSuggestions( $builder, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testMultipleCoordinates() {
		$doc = [
			'coordinates' => [
				[
					'coord' => [
						'lat' => 0.70777777777778,
						'lon' => -50.089444444444
					],
					'region' => null,
					'dim' => 10000,
					'name' => "",
					'primary' => true,
					'type' => "river",
					'globe' => "earth",
					'country' => "BR"
				],
				[
					'coord' => [
						'lat' => -15.518055555556,
						'lon' => -71.765277777778
					],
					'region' => null,
					'dim' => 10000,
					'name' => "",
					'primary' => false,
					'type' => "river",
					'globe' => "earth",
					'country' => "BR"
				]
			]
		];

		$builder = new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks' ) );
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = [ 'lat' => 0.70777777777778, 'lon' => -50.089444444444 ];
		$this->assertSame( $expected, $coord );

		$doc['coordinates'][1]['primary'] = true;
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = [ 'lat' => 0.70777777777778, 'lon' => -50.089444444444 ];
		$this->assertSame( $expected, $coord, "With two primaries coord we choose the first one" );

		$doc['coordinates'][0]['primary'] = false;
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = [ 'lat' => -15.518055555556, 'lon' => -71.765277777778 ];
		$this->assertSame( $expected, $coord, "Choose primary coord even if it's not the first one." );

		$doc['coordinates'][1]['primary'] = false;
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = [ 'lat' => 0.70777777777778, 'lon' => -50.089444444444 ];
		$this->assertSame( $expected, $coord, "Choose first coord if there's no primary." );

		$doc['coordinates'][0]['primary'] = true;
		$doc['coordinates'][0]['globe'] = 'Magrathea';
		$coord = $builder->findPrimaryCoordinates( $doc );
		$expected = [ 'lat' => -15.518055555556, 'lon' => -71.765277777778 ];
		$this->assertSame( $expected, $coord, "Choose first coord on earth." );

		$doc['coordinates'][1]['globe'] = 'Magrathea';
		$coord = $builder->findPrimaryCoordinates( $doc );
		$this->assertNull( $coord, "No coord if none is on earth." );
	}

	/**
	 * @dataProvider provideOutputEncoder
	 */
	public function testOutputEncoder( $expected, $encoded ) {
		$this->assertEquals( $expected, SuggestBuilder::decodeOutput( $encoded ) );
	}

	public function provideOutputEncoder() {
		return [
			'title' => [
				[
					'docId' => '123',
					'type' => SuggestBuilder::TITLE_SUGGESTION,
					'text' => 'This is a title',
				],
				SuggestBuilder::encodeTitleOutput( 123, "This is a title" ),
			],
			'redirect' => [
				[
					'docId' => '123',
					'type' => SuggestBuilder::REDIRECT_SUGGESTION,
				],
				SuggestBuilder::encodeRedirectOutput( 123 ),
			],
			'Garbage' => [
				null,
				'Garbage',
			],
			'Broken title' => [
				null,
				'123:t',
			],
			'Partial encoding' => [
				null,
				'123:',
			],
			'null output' => [
				null,
				null,
			],
		];
	}

	private function buildSuggestions( $builder, $doc ) {
		return array_map( function( $x ) {
				$dat = $x->getData();
				unset( $dat['batch_id'] );
				return $dat;
			}, $builder->build( [ [ 'id' => 1, 'source' => $doc ] ] ) );
	}
}
