<?php

namespace CirrusSearch;

use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use CirrusSearch\BuildDocument\Completion\GeoSuggestionsBuilder;
use CirrusSearch\BuildDocument\Completion\DefaultSortSuggestionsBuilder;
use CirrusSearch\BuildDocument\Completion\NaiveSubphrasesSuggestionsBuilder;
use CirrusSearch\BuildDocument\Completion\SuggestScoringMethodFactory;

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
 *
 * @group CirrusSearch
 */
class SuggestBuilderTest extends \MediaWikiTestCase {
	public function testEinstein() {
		$builder = $this->buildBuilder( 'incomingLinks' );
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

	public function testDefaultSort() {
		$builder = $this->buildBuilder( 'incomingLinks' );
		$this->assertContains( 'defaultsort', $builder->getRequiredFields() );
		$score = 10;
		$redirScore = (int) ( $score * SuggestBuilder::REDIRECT_DISCOUNT );
		$doc = [
			'title' => 'Albert Einstein',
			'namespace' => 0,
			'defaultsort' => 'Einstein, Albert',
			'redirect' => [
				[ 'title' => "Albert Enstein", 'namespace' => 0 ],
				[ 'title' => "Einstein", 'namespace' => 0 ],
			],
			'incoming_links' => $score
		];
		$expected = [
			[
				'suggest' => [
					'input' => [ 'Albert Einstein', 'Albert Enstein', 'Einstein, Albert' ],
					'output' => '1:t:Albert Einstein',
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'Albert Einstein', 'Albert Enstein', 'Einstein, Albert' ],
					'output' => '1:t:Albert Einstein',
					'weight' => $score
				]
			],
			[
				'suggest' => [
					'input' => [ 'Einstein' ],
					'output' => '1:r',
					'weight' => $redirScore
				],
				'suggest-stop' => [
					'input' => [ 'Einstein' ],
					'output' => '1:r',
					'weight' => $redirScore
				]
			]
		];

		$crossNsScore = (int) ($score * SuggestBuilder::CROSSNS_DISCOUNT);

		$suggestions = $this->buildSuggestions( $builder, $doc );
		$this->assertSame( $expected, $suggestions );

		// Test Cross namespace the defaultsort should not be added
		// to cross namespace redirects
		$doc = [
			'title' => 'Guidelines for XYZ',
			'namespace' => NS_HELP,
			'defaultsort' => 'XYZ, Guidelines',
			'redirect' => [
				[ 'title' => "GXYZ", 'namespace' => 0 ],
				[ 'title' => "XYZG", 'namespace' => 0 ],
			],
			'incoming_links' => $score
		];
		$expected = [
			[
				'suggest' => [
					'input' => [ 'GXYZ' ],
					'output' => '0:t:GXYZ',
					'weight' => $crossNsScore
				],
				'suggest-stop' => [
					'input' => [ 'GXYZ' ],
					'output' => '0:t:GXYZ',
					'weight' => $crossNsScore
				]
			],
			[
				'suggest' => [
					'input' => [ 'XYZG' ],
					'output' => '0:t:XYZG',
					'weight' => $crossNsScore
				],
				'suggest-stop' => [
					'input' => [ 'XYZG' ],
					'output' => '0:t:XYZG',
					'weight' => $crossNsScore
				]
			]
		];

		$suggestions = $this->buildSuggestions( $builder, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testEraq() {
		$builder = $this->buildBuilder( 'incomingLinks' );
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
		$builder = $this->buildBuilder( 'incomingLinks' );
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
		$builder = $this->buildBuilder( 'incoming_links' );
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

		$builder = new GeoSuggestionsBuilder();
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

	/**
	 * @dataProvider providePagesForSubphrases
	 */
	public function testSubphrasesSuggestionsBuilder( $input, $langSubPage, $type, $max, array $output ) {
		$config = ['limit' => $max, 'type' => $type];
		$builder = NaiveSubphrasesSuggestionsBuilder::create( $config );
		$subPageSuggestions = $builder->tokenize( $input, $langSubPage );
		$this->assertEquals( $output, $subPageSuggestions );
	}

	public function providePagesForSubphrases() {
		return [
			'none subpage' => [
				'Hello World',
				'',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				[]
			],
			'none any words' => [
				'Hello World',
				'',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				['World']
			],
			'none subpage translated' => [
				'Hello World/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				[],
			],
			'none any words translated' => [
				'Hello World/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				['World/ru'],
			],
			'simple subphrase' => [
				'Hyperion Cantos/Hyperion',
				'en',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				['Hyperion'],
			],
			'simple any words' => [
				'Hyperion Cantos/Hyperion',
				'en',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				['Cantos/Hyperion', 'Hyperion'],
			],
			'simple subpage translated' => [
				'Hyperion Cantos/Hyperion/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				['Hyperion/ru'],
			],
			'simple any words translated' => [
				'Hyperion Cantos/Hyperion/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				['Cantos/Hyperion/ru', 'Hyperion/ru'],
			],
			'multiple subpage' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale',
				'en',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				[
					'Hyperion/The Priest\'s Tale',
					'The Priest\'s Tale'
				],
			],
			'multiple any words' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale',
				'en',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				10,
				[
					'Cantos/Hyperion/The Priest\'s Tale',
					'Hyperion/The Priest\'s Tale',
					'The Priest\'s Tale',
					'Priest\'s Tale',
					'Tale'
				],
			],
			'multiple subpage translated' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				[
					'Hyperion/The Priest\'s Tale/ru',
					'The Priest\'s Tale/ru'
				],
			],
			'multiple any words translated' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				10,
				[
					'Cantos/Hyperion/The Priest\'s Tale/ru',
					'Hyperion/The Priest\'s Tale/ru',
					'The Priest\'s Tale/ru',
					'Priest\'s Tale/ru',
					'Tale/ru',
				],
			],
			'multiple subpage limited' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale/Part One',
				'en',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				2,
				[
					'Hyperion/The Priest\'s Tale/Part One',
					'The Priest\'s Tale/Part One'
				],
			],
			'multiple any words limited' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale/Part One',
				'en',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				2,
				[
					'Cantos/Hyperion/The Priest\'s Tale/Part One',
					'Hyperion/The Priest\'s Tale/Part One',
				],
			],
			'multiple translated subpage limited' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale/Part One/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				2,
				[
					'Hyperion/The Priest\'s Tale/Part One/ru',
					'The Priest\'s Tale/Part One/ru'
				],
			],
			'multiple translated any words limited' => [
				'Hyperion Cantos/Hyperion/The Priest\'s Tale/Part One/ru',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				2,
				[
					'Cantos/Hyperion/The Priest\'s Tale/Part One/ru',
					'Hyperion/The Priest\'s Tale/Part One/ru',
				],
			],
			'empty subpage' => [
				'Hyperion Cantos//Hyperion',
				'en',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				['Hyperion'],
			],
			'empty subpage anywords' => [
				'Hyperion Cantos//Hyperion',
				'en',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				['Cantos//Hyperion', 'Hyperion'],
			],
			'misplace lang subpage' => [
				'Hyperion Cantos/ru/Hyperion',
				'ru',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				['ru/Hyperion', 'Hyperion'],
			],
			'missing subpage' => [
				'Hyperion Cantos/',
				'en',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				[]
			],
			'orphan subpage' => [
				'/Hyperion Cantos/Hyperion',
				'en',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				[ 'Hyperion' ]
			],
			'starts with space' => [
				' Hyperion',
				'en',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				[]
			],
			'edge case with empty title' => [
				'',
				'en',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				[]
			],
			'edge case with only split chars' => [
				'//',
				'en',
				NaiveSubphrasesSuggestionsBuilder::SUBPAGE_TYPE,
				3,
				[]
			],
			'edge case with only split chars #2' => [
				' / / /en',
				'en',
				NaiveSubphrasesSuggestionsBuilder::STARTS_WITH_ANY_WORDS_TYPE,
				3,
				[]
			]
		];
	}

	private function buildBuilder( $scoringMethod ) {
		$extra = [
			new GeoSuggestionsBuilder(),
			new DefaultSortSuggestionsBuilder(),
		];
		return new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks' ), $extra );
	}
}
