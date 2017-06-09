<?php

namespace CirrusSearch;

use CirrusSearch\Test\HashSearchConfig;
use CirrusSearch\Test\DummyConnection;
use CirrusSearch\BuildDocument\Completion\SuggestBuilder;

/**
 * Completion Suggester Tests
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
class CompletionSuggesterTest extends CirrusTestCase {

	/**
	 * @dataProvider provideQueries
	 */
	public function testQueries( $config, $limit, $search, $variants, $expectedProfiles, $expectedQueries ) {
		$completion = new MyCompletionSuggester( new HashSearchConfig( $config ), $limit );
		list( $profiles, $suggest ) = $completion->testBuildQuery( $search, $variants );
		$this->assertEquals( $expectedProfiles, $profiles );
		$this->assertEquals( $expectedQueries, $suggest );
	}

	public function provideQueries() {
		$simpleProfile = [
			'plain' => [
				'field' => 'suggest',
				'min_query_len' => 0,
				'discount' => 1.0,
				'fetch_limit_factor' => 2,
			],
		];

		$simpleFuzzy = $simpleProfile + [
			'plain-fuzzy' => [
				'field' => 'suggest',
				'min_query_len' => 0,
				'fuzzy' => [
					'fuzziness' => 'AUTO',
					'prefix_length' => 1,
					'unicode_aware' => true,
				],
				'discount' => 0.5,
				'fetch_limit_factor' => 1.5
			]
		];

		$profile = [
			'simple' => $simpleProfile,
			'fuzzy' => $simpleFuzzy,
		];

		return [
			"simple" => [
				[
					'CirrusSearchCompletionSettings' => 'simple',
					'CirrusSearchCompletionProfiles' => $profile,
				],
				10,
				' complete me ',
				null,
				$simpleProfile, // The profile remains unmodified here
				[
					'plain' => [
						'prefix' => 'complete me ', // keep trailing white spaces
						'completion' => [
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						],
					],
				],
			],
			"simple with fuzzy" => [
				[
					'CirrusSearchCompletionSettings' => 'fuzzy',
					'CirrusSearchCompletionProfiles' => $profile,
				],
				10,
				' complete me ',
				null,
				$simpleFuzzy, // The profiles remains unmodified here
				[
					'plain' => [
						'prefix' => 'complete me ', // keep trailing white spaces
						'completion' => [
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						],
					],
					'plain-fuzzy' => [
						'prefix' => 'complete me ', // keep trailing white spaces
						'completion' => [
							'field' => 'suggest',
							'size' => 15.0, // effect of fetch_limit_factor
							// fuzzy config is simply copied from the profile
							'fuzzy' => [
								'fuzziness' => 'AUTO',
								'prefix_length' => 1,
								'unicode_aware' => true,
							],
						],
					],
				],
			],
			"simple with variants" => [
				[
					'CirrusSearchCompletionSettings' => 'simple',
					'CirrusSearchCompletionProfiles' => $profile,
				],
				10,
				' complete me ',
				[ ' variant1 ', ' complete me ', ' variant2 ' ],
				// Profile is updated with extra variant setup
				// to include an extra discount
				// ' complete me ' variant duplicate will be ignored
				$simpleProfile + [
					'plain-variant-1' => [
						'field' => 'suggest',
						'min_query_len' => 0,
						'discount' => 1.0 * CompletionSuggester::VARIANT_EXTRA_DISCOUNT,
						'fetch_limit_factor' => 2,
						'fallback' => true, // extra key added, not used for now
					],
					'plain-variant-2' => [
						'field' => 'suggest',
						'min_query_len' => 0,
						'discount' => 1.0 * (CompletionSuggester::VARIANT_EXTRA_DISCOUNT/2),
						'fetch_limit_factor' => 2,
						'fallback' => true, // extra key added, not used for now
					]
				],
				[
					'plain' => [
						'prefix' => 'complete me ', // keep trailing white spaces
						'completion' => [
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						],
					],
					'plain-variant-1' => [
						'prefix' => 'variant1 ',
						'completion' => [
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						],
					],
					'plain-variant-2' => [
						'prefix' => 'variant2 ',
						'completion' => [
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						],
					],
				],
			],
		];
	}

	/**
	 * @dataProvider provideMinMaxQueries
	 */
	public function testMinMaxDefaultProfile( $len, $query ) {
		global $wgCirrusSearchCompletionProfiles;
		$config = [
			'CirrusSearchCompletionSettings' => 'fuzzy',
			'CirrusSearchCompletionProfiles' => $wgCirrusSearchCompletionProfiles,
		];
		// Test that we generate at most 4 profiles
		$completion = new MyCompletionSuggester( new HashSearchConfig( $config ), 1 );
		list( $profiles, $suggest ) = $completion->testBuildQuery( $query, [] );
		// Unused profiles are kept
		$this->assertEquals( count( $wgCirrusSearchCompletionProfiles['fuzzy'] ), count( $profiles ) );
		// Never run more than 4 suggest query (without variants)
		$this->assertTrue( count( $suggest ) <= 4 );
		// small queries
		$this->assertTrue( count( $suggest ) >= 2 );

		if ( $len < 3 ) {
			// We do not run fuzzy for small queries
			$this->assertEquals( 2, count( $suggest ) );
			foreach( $suggest as $key => $value ) {
				$this->assertArrayNotHasKey( 'fuzzy', $value );
			}
		}
		foreach( $suggest as $key => $value ) {
			// Make sure the query is truncated otherwise elastic won't send results
			$this->assertTrue( mb_strlen( $value['prefix'] ) < SuggestBuilder::MAX_INPUT_LENGTH );
		}
		foreach( array_keys( $suggest ) as $sug ) {
			// Makes sure we have the corresponding profile
			$this->assertArrayHasKey( $sug, $profiles );
		}
	}

	public function provideMinMaxQueries() {
		$queries = [];
		// The completion should not count extra spaces
		// This is to avoid enbling costly fuzzy profiles
		// by cheating with spaces
		$query = '  ';
		for( $i = 0; $i < 100; $i++ ) {
			$test = "Query length {$i}";
			$queries[$test] = [ $i, $query . '   ' ];
			$query .= '';
		}
		return $queries;
	}

	/**
	 * @dataProvider provideResponse
	 */
	public function testOffsets( \Elastica\Response $resp, $limit, $offset, $first, $last, $size, $hardLimit ) {
		global $wgCirrusSearchCompletionProfiles;
		$config = [
			'CirrusSearchCompletionSettings' => 'fuzzy',
			'CirrusSearchCompletionProfiles' => $wgCirrusSearchCompletionProfiles,
			'CirrusSearchCompletionSuggesterHardLimit' => $hardLimit,
		];
		// Test that we generate at most 4 profiles
		$completion = new MyCompletionSuggester( new HashSearchConfig( $config ), $limit, $offset );

		$log = $this->getMockBuilder( CompletionRequestLog::class )
			->disableOriginalConstructor()
			->getMock();
		$suggestions = $completion->testPostProcess( 'Tit', $resp, $log );
		$this->assertEquals( $size, $suggestions->getSize() );
		if ( $size > 0 ) {
			$suggestions = $suggestions->getSuggestions();
			$firstS = reset( $suggestions );
			$lastS = end( $suggestions );
			$this->assertEquals( $first, $firstS->getText() );
			$this->assertEquals( $last, $lastS->getText() );
		}
	}

	public function provideResponse() {
		$suggestions = [];
		$max = 200;
		for( $i = 1; $i <= $max; $i++ ) {
			$score = $max - $i;
			$suggestions[] = [
				'_id' => $i.'t',
				'text'=> "Title$i",
				'_score' => $score,
			];
		}

		$suggestData = [ [
					'prefix' => 'Tit',
					'options' => $suggestions
				] ];

		$data = [
			'suggest' => [
				'plain' => $suggestData,
				'plain_fuzzy_2' => $suggestData,
				'plain_stop' => $suggestData,
				'plain_stop_fuzzy_2' => $suggestData,
			],
		];
		$resp = new \Elastica\Response( $data );
		return [
			'Simple offset 0' => [
				$resp,
				5, 0, 'Title1', 'Title5', 5, 50
			 ],
			'Simple offset 5' => [
				$resp,
				5, 5, 'Title6', 'Title10', 5, 50
			 ],
			'Reach ES limit' => [
				$resp,
				5, $max-3, 'Title198', 'Title200', 3, 300
			 ],
			'Reach Cirrus limit' => [
				$resp,
				5, 47, 'Title48', 'Title50', 3, 50
			 ],
			'Out of Cirrus bounds' => [
				$resp,
				5, 67, null, null, 0, 50
			 ],
			'Out of elastic results' => [
				$resp,
				5, 200, null, null, 0, 300
			 ],
		];

	}
}

/**
 * No package visibility in with PHP so we have to subclass...
 */
class MyCompletionSuggester extends CompletionSuggester {
	public function __construct( SearchConfig $config, $limit, $offset=0 ) {
		parent::__construct( new DummyConnection(), $limit, $offset, $config, [ NS_MAIN ], null, "dummy" );
	}

	public function testBuildQuery( $search, $variants ) {
		$this->setTermAndVariants( $search, $variants );
		return $this->buildQuery();
	}

	public function testPostProcess( $search, \Elastica\Response $resp, CompletionRequestLog $log ) {
		$this->setTermAndVariants( $search );
		list( $profiles ) = $this->buildQuery();
		return $this->postProcessSuggest( $resp, $profiles, $log );
	}
}
