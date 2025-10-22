<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusTestCase;

/**
 * @covers \CirrusSearch\Maintenance\AnalysisFilter
 */
class AnalysisFilterTest extends CirrusTestCase {
	public static function usedAnalysisComponentsProvider(): array {
		return [
			'empty' => [ [], [] ],
			'type with no properties' => [ [], [
				'example_type' => [
					'properties' => [],
				],
			] ],
			'read field analyzer/normalizer' => [
				[ 'analyzers' => [ 'hello' ], 'normalizers' => [ 'hello_normalizer' ] ],
				[
					'example_type' => [
						'properties' => [
							'title' => [
								'analyzer' => 'hello'
							],
							'title_keyword' => [
								'normalizer' => 'hello_normalizer'
							],
						],
					],
				]
			],
			'read field search analyzer' => [ [ 'analyzers' => [ 'world' ] ], [
				'example_type' => [
					'properties' => [
						'title' => [
							'search_analyzer' => 'world'
						],
					],
				],
			] ],
			'read subfield analyzer/normalizer' => [
				[ 'analyzers' => [ 'analysis' ], 'normalizers' => [ 'analysis_normalizer' ] ],
				[
					'example_type' => [
						'properties' => [
							'title' => [
								'fields' => [
									'my_subfield' => [
										'analyzer' => 'analysis',
									],
									'keyword' => [
										'normalizer' => 'analysis_normalizer',
									]
								]
							],
						],
					],
				]
			],
			'read subfield search_analyzer' => [ [ 'analyzers' => [ 'chains' ] ], [
				'example_type' => [
					'properties' => [
						'title' => [
							'fields' => [
								'my_subfield' => [
									'search_analyzer' => 'chains',
								],
							]
						],
					],
				],
			] ],
			'read subproperty analyzer/normalizer' => [
				[ 'analyzers' => [ 'could be' ], 'normalizers' => [ 'might be' ] ],
				[
					'example_type' => [
						'properties' => [
							'title' => [
								'properties' => [
									'my_subfield' => [
										'analyzer' => 'could be',
									],
									'my_keyword_subfield' => [
										'normalizer' => 'might be',
									]
								]
							],
						],
					],
				]
			],
			'read subproperty search analyzer' => [
				[ 'analyzers' => [ 'filtered' ] ],
				[
					'example_type' => [
						'properties' => [
							'title' => [
								'properties' => [
									'my_subfield' => [
										'search_analyzer' => 'filtered',
									]
								]
							],
						],
					],
				]
			],
			'properties with sub fields' => [
				[
					'analyzers' => [ 'text', 'text_search', 'aa_plain', 'aa_plain_search', 'ab_plain', 'ab_plain_search' ],
					'normalizers' => [ 'aa_normalizer', 'ab_normalizer' ]
				],
				[
					'my_type' => [
						'properties' => [
							'title' => [
								'analyzer' => 'text',
								'search_analyzer' => 'text_search',
							],
							'labels' => [
								'properties' => [
									'aa' => [
										'type' => 'text',
										'index' => false,
										'fields' => [
											'keyword' => [
												'normalizer' => 'aa_normalizer',
											],
											'plain' => [
												'analyzer' => 'aa_plain',
												'search_analyzer' => 'aa_plain_search',
											],
										],
									],
									'ab' => [
										'type' => 'text',
										'index' => false,
										'fields' => [
											'keyword' => [
												'normalizer' => 'ab_normalizer',
											],
											'plain' => [
												'analyzer' => 'ab_plain',
												'search_analyzer' => 'ab_plain_search',
											],
										],
									],
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * @dataProvider usedAnalysisComponentsProvider
	 */
	public function testFindUsedAnalyzersInMappings( $names, $mappings ) {
		$filter = new AnalysisFilter();

		$foundAnalyzers = $filter->findUsedAnalyzersInMappings( $mappings )->values();
		$foundNormalizers = $filter->findUsedNormalizersInMappings( $mappings )->values();

		$expectedAnalyzers = $names['analyzers'] ?? [];
		$expectedNormalizers = $names['normalizers'] ?? [];
		asort( $foundAnalyzers );
		asort( $foundNormalizers );
		asort( $expectedAnalyzers );
		asort( $expectedNormalizers );
		$this->assertEquals( array_values( $expectedAnalyzers ), array_values( $foundAnalyzers ) );
		$this->assertEquals( array_values( $expectedNormalizers ), array_values( $foundNormalizers ) );
	}

	/**
	 * @dataProvider usedAnalysisComponentsProvider
	 */
	public function testPushAnalyzerAliasesIntoMappings( $names, $mappings ) {
		$analyzerNames = $names['analyzers'] ?? [];
		$aliases = array_combine( $analyzerNames, array_map( 'strrev', $analyzerNames ) );
		$filter = new AnalysisFilter();
		$updated = $filter->pushAnalyzerAliasesIntoMappings( $mappings, $aliases );
		$found = $filter->findUsedAnalyzersInMappings( $updated )->values();

		$expected = array_unique( array_values( $aliases ) );
		asort( $expected );
		asort( $found );
		$this->assertEquals( $expected, $found );
	}

	/**
	 * @dataProvider usedAnalysisComponentsProvider
	 */
	public function testPushNormalizerAliasesIntoMappings( $names, $mappings ) {
		$normalizerNames = $names['normalizers'] ?? [];
		$aliases = array_combine( $normalizerNames, array_map( 'strrev', $normalizerNames ) );
		$filter = new AnalysisFilter();
		$updated = $filter->pushNormalizerAliasesIntoMappings( $mappings, $aliases );
		$found = $filter->findUsedNormalizersInMappings( $updated )->values();

		$expected = array_unique( array_values( $aliases ) );
		asort( $expected );
		asort( $found );
		$this->assertEquals( $expected, $found );
	}

	public static function filterUnusedProvider() {
		return [
			'empty' => [
				[ 'analyzer' => [] ], [], [ 'analyzer' => [] ]
			],
			'doesnt remove used analyzer' => [ [
					'analyzer' => [ 'still here' ],
				],
				[ 'still here' ],
				[
					'analyzer' => [
						'still here' => [],
					],
				],
			],
			'removes unused analyzer' => [ [
					'analyzer' => [ 'still here' ],
				],
				[ 'still here' ],
				[
					'analyzer' => [
						'still here' => [],
						'missing' => [],
					],
				],
			],
			'removes unused filter/char_filter/tokenizer' => [ [
					'analyzer' => [ 'still here' ],
					'filter' => [],
					'char_filter' => [],
					'tokenizer' => [],
				],
				[ 'still here' ],
				[
					'analyzer' => [
						'still here' => [],
					],
					'filter' => [
						'not me' => [],
					],
					'char_filter' => [
						'or me' => [],
					],
					'tokenizer' => [
						'me either' => [],
					],
				],
			],
			'keeps used filter/char_filter/tokenizer' => [ [
					'analyzer' => [ 'still here' ],
					'filter' => [ 'and me' ],
					'char_filter' => [ 'reporting' ],
					'tokenizer' => [ 'rainbows' ],
				],
				[ 'still here' ],
				[
					'analyzer' => [
						'still here' => [
							'tokenizer' => 'rainbows',
							'filter' => [ 'and me' ],
							'char_filter' => [ 'reporting' ],
						],
					],
					'filter' => [
						'and me' => [],
					],
					'char_filter' => [
						'reporting' => [],
					],
					'tokenizer' => [
						'rainbows' => [],
					],
				],
			],
			'removes items referenced by removed analyzer' => [ [
					'analyzer' => [ 'still here' ],
					'filter' => [],
					'char_filter' => [],
					'tokenizer' => [],
				],
				[ 'still here' ],
				[
					'analyzer' => [
						'still here' => [],
						'delete me' => [
							'tokenizer' => 'rainbows',
							'filter' => [ 'and me' ],
							'char_filter' => [ 'reporting' ],
						],
					],
					'filter' => [
						'and me' => [],
					],
					'char_filter' => [
						'reporting' => [],
					],
					'tokenizer' => [
						'rainbows' => [],
					],
				],
			],
		];
	}

	/**
	 * @dataProvider filterUnusedProvider
	 */
	public function testFilterUnusedAnalysisChain( $expected, $usedAnalyzers, $analysis ) {
		$filter = new AnalysisFilter();
		$updated = $filter->filterUnusedAnalysisChain( $analysis, new Set( $usedAnalyzers ), new Set( [] ) );
		foreach ( $expected as $key => $values ) {
			if ( count( $values ) ) {
				$this->assertArrayHasKey( $key, $updated );
				$found = array_keys( $updated[$key] );
				$this->assertEquals( $values, $found, $key );
			} elseif ( isset( $updated[$key] ) ) {
				$this->assertCount( 0, $updated[$key] );
			} else {
				// silence risky test warning
				$this->assertArrayNotHasKey( $key, $updated );
			}
		}
	}

	public static function deduplicateProvider() {
		return [
			'empty' => [
				[ [], [] ],
				[]
			],
			'simple example' => [
				[
					[ 'a' => 'a', 'b' => 'a', ],
					[ 'a' => 'a', 'b' => 'a', ],
				], [
					'analyzer' => [
						'a' => [
							'tokenizer' => 'whitespace',
						],
						'b' => [
							'tokenizer' => 'whitespace',
						],
					],
					'normalizer' => [
						'a' => [
							'type' => 'custom',
						],
						'b' => [
							'type' => 'custom',
						],
					],
				],
			],
			'deduplication is stable (part 1)' => [
				[
					[ 'a' => 'a', 'b' => 'a' ],
					[ 'a' => 'a', 'b' => 'a' ],
				], [
					'analyzer' => [
						'a' => [ 'foo' => 'bar' ],
						'b' => [ 'foo' => 'bar' ],
					],
					'normalizer' => [
						'a' => [ 'foo' => 'bar' ],
						'b' => [ 'foo' => 'bar' ],
					]
				],
			],
			'deduplication is stable (part 2)' => [
				[
					[ 'a' => 'a', 'b' => 'a', ],
					[ 'a' => 'a', 'b' => 'a', ],
				], [
					'analyzer' => [
						'b' => [ 'foo' => 'bar' ],
						'a' => [ 'foo' => 'bar' ],
					],
					'normalizer' => [
						'b' => [ 'foo' => 'bar' ],
						'a' => [ 'foo' => 'bar' ],
					]
				],
			],
			'filter and char_filter order is respected' => [
				[
					[ 'a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd' ],
					[ 'a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd' ],
				], [
					'analyzer' => [
						'a' => [
							'filter' => [ 'filter_a', 'filter_b' ],
						],
						'b' => [
							'filter' => [ 'filter_b', 'filter_a' ],
						],
						'c' => [
							'char_filter' => [ 'char_filter_b', 'char_filter_a' ],
						],
						'd' => [
							'char_filter' => [ 'char_filter_a', 'char_filter_b' ],
						],
					],
					'normalizer' => [
						'a' => [
							'filter' => [ 'filter_a', 'filter_b' ],
						],
						'b' => [
							'filter' => [ 'filter_b', 'filter_a' ],
						],
						'c' => [
							'char_filter' => [ 'char_filter_b', 'char_filter_a' ],
						],
						'd' => [
							'char_filter' => [ 'char_filter_a', 'char_filter_b' ],
						],
					],
					'char_filter' => [
						'char_filter_a' => [ 'foo' => 'bar' ],
						'char_filter_b' => [ 'bar' => 'foo' ],
					],
					'filter' => [
						'filter_a' => [ 'foo' => 'bar' ],
						'filter_b' => [ 'bar' => 'foo' ],
					],
				]
			],
			'applies deduplication at multiple levels' => [
				[
					[ 'a' => 'a', 'b' => 'a', 'c' => 'c', ],
					[ 'a' => 'a', 'b' => 'a', 'c' => 'c', ],
				], [
					'analyzer' => [
						'a' => [
							'tokenizer' => 'foo',
							'filter' => [ 'too many' ],
							'char_filter' => [ 'some_filter_a', 'unrelated' ],
						],
						'b' => [
							'char_filter' => [ 'some_filter_b', 'unrelated' ],
							'tokenizer' => 'bar',
							'filter' => [ 'random strings' ],
						],
						'c' => [
							'char_filter' => [ 'some_filter_b' ],
							'tokenizer' => 'bar',
							'filter' => [ 'random strings' ],
						],
					],
					'normalizer' => [
						'a' => [
							'filter' => [ 'too many' ],
							'char_filter' => [ 'some_filter_a', 'unrelated' ],
						],
						'b' => [
							'char_filter' => [ 'some_filter_b', 'unrelated' ],
							'filter' => [ 'random strings' ],
						],
						'c' => [
							'char_filter' => [ 'some_filter_b' ],
							'filter' => [ 'random strings' ],
						],
					],
					'tokenizer' => [
						'foo' => [
							'looks' => 'the same',
							'but in' => 'different order',
						],
						'bar' => [
							'but in' => 'different order',
							'looks' => 'the same',
						],
					],
					'char_filter' => [
						'unrelated' => [ 'other' => 'things' ],
						'some_filter_a' => [ 'qwerty' => 'azerty' ],
						'some_filter_b' => [ 'qwerty' => 'azerty' ],
					],
					'filter' => [
						'too many' => [ 'things'  => 'to write' ],
						'random strings' => [ 'things' => 'to write' ],
					],
				],
			],
		];
	}

	/**
	 * @dataProvider deduplicateProvider
	 */
	public function testDeduplicateAnalysisConfig( $expected, $analysis ) {
		$filter = new AnalysisFilter();
		$aliases = $filter->deduplicateAnalysisConfig( $analysis );
		$this->assertEquals( $expected, $aliases );
	}

	/**
	 * @covers \CirrusSearch\Maintenance\AnalysisFilter::filterAnalysis
	 */
	public function testExcludesProtectedAnalyzers() {
		$filter = new AnalysisFilter();
		$analysis = [
			'analyzer' => [
				'text_search_a' => [
					'tokenizer' => 'foo',
				],
				'text_search_b' => [
					'tokenizer' => 'foo',
				],
			],
		];
		$mappings = [
			'my_type' => [
				'properties' => [
					'title' => [
						'analyzer' => 'text',
						'search_analyzer' => 'text_search_a',
					],
				],
			],
		];

		[ $analysis, $mappings ] = $filter->filterAnalysis( $analysis, $mappings, true, [ 'text_search_b' ] );
		$this->assertArrayHasKey( 'text_search_b', $analysis['analyzer'] );
	}

	/**
	 * @covers \CirrusSearch\Maintenance\AnalysisFilter::filterAnalysis
	 */
	public function testPrimaryEntrypoint() {
		$filter = new AnalysisFilter();
		$initialAnalysis = [
			'filter' => [
				'icu_normalizer' => [],
				'truncate_norm' => [],
			],
			'char_filter' => [
				'word_break_helper' => [],
			],
			'tokenizer' => [],
			'analyzer' => [
				'aa_plain' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'char_filter' => [ 'word_break_helper' ],
					'filter' => [ 'icu_normalizer' ],
				],
				'aa_plain_search' => [
					'tokenizer' => 'snowball',
				],
				'ab_plain' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'char_filter' => [ 'word_break_helper' ],
					'filter' => [ 'icu_normalizer' ],
				],
				'ab_plain_search' => [
					'tokenizer' => 'snowball',
				],
				'text' => [
					'tokenizer' => 'whitespace',
				],
				'text_search' => [
					'tokenizer' => 'whitespace',
				],
			],
			'normalizer' => [
				'aa_keyword' => [
					'type' => 'custom',
					'filter' => [ 'truncate_norm' ]
				],
				'ab_keyword' => [
					'type' => 'custom',
					'filter' => [ 'truncate_norm' ]
				]
			]
		];
		$initialMappings = [
			'my_type' => [
				'properties' => [
					'title' => [
						'analyzer' => 'text',
						'search_analyzer' => 'text_search',
					],
					'labels' => [
						'properties' => [
							'aa' => [
								'type' => 'text',
								'index' => false,
								'fields' => [
									'plain' => [
										'analyzer' => 'aa_plain',
										'search_analyzer' => 'aa_plain_search',
									],
									'keyword' => [
										'normalizer' => 'aa_keyword',
									]
								],
							],
							'ab' => [
								'type' => 'text',
								'index' => false,
								'fields' => [
									'plain' => [
										'analyzer' => 'ab_plain',
										'search_analyzer' => 'ab_plain_search',
									],
									'keyword' => [
										'normalizer' => 'ab_keyword',
									]
								],
							],
						],
					],
				],
			],
		];
		[ $analysis, $mappings ] = $filter->filterAnalysis(
			$initialAnalysis, $initialMappings, true );

		$this->assertArrayHasKey( 'aa', $mappings['my_type']['properties']['labels']['properties'] );
		$this->assertArrayHasKey( 'ab', $mappings['my_type']['properties']['labels']['properties'] );

		$debug = print_r( $analysis['analyzer'], true );
		$this->assertArrayHasKey( 'aa_plain', $analysis['analyzer'], $debug );
		$this->assertArrayNotHasKey( 'ab_plain', $analysis['analyzer'], $debug );
		$this->assertArrayHasKey( 'text', $analysis['analyzer'], $debug );
		$this->assertArrayNotHasKey( 'text_search', $analysis['analyzer'], $debug );

		$debug = print_r( $analysis['normalizer'], true );
		$this->assertArrayHasKey( 'aa_keyword', $analysis['normalizer'], $debug );
		$this->assertArrayNotHasKey( 'ab_keyword', $analysis['normalizer'], $debug );

		$debug = print_r( $mappings['my_type']['properties'], true );
		$title = $mappings['my_type']['properties']['title'];
		$this->assertEquals( 'text', $title['analyzer'], $debug );
		$this->assertEquals( 'text', $title['search_analyzer'], $debug );

		$aa = $mappings['my_type']['properties']['labels']['properties']['aa']['fields']['plain'];
		$this->assertEquals( 'aa_plain', $aa['analyzer'], $debug );
		$this->assertEquals( 'aa_plain_search', $aa['search_analyzer'], $debug );

		$ab = $mappings['my_type']['properties']['labels']['properties']['ab']['fields']['plain'];
		$this->assertEquals( 'aa_plain', $ab['analyzer'], $debug );
		$this->assertEquals( 'aa_plain_search', $ab['search_analyzer'], $debug );

		$aa = $mappings['my_type']['properties']['labels']['properties']['aa']['fields']['keyword'];
		$this->assertEquals( 'aa_keyword', $aa['normalizer'], $debug );

		$ab = $mappings['my_type']['properties']['labels']['properties']['ab']['fields']['keyword'];
		$this->assertEquals( 'aa_keyword', $ab['normalizer'], $debug );
	}
}
