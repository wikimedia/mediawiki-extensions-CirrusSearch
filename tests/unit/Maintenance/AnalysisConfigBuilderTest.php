<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Test\HashSearchConfig;
use CirrusSearch\CirrusTestCase;

/**
 * @group CirrusSearch
 * @covers CirrusSearch\Maintenance\AnalysisConfigBuilder
 */
class AnalysisConfigBuilderTest extends CirrusTestCase {
	/** @dataProvider provideASCIIFoldingFilters */
	public function testASCIIFoldingFix( array $input, array $expected ) {
		$config = new HashSearchConfig([]);
		$plugins = ['extra', 'analysis-icu'];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config );
		$result = $builder->fixAsciiFolding( $input );

		$this->assertEquals( $expected['analyzer'], $result['analyzer'] );
		if ( isset( $expected['filter'] ) ) {
			$this->assertEquals( $expected['filter'], $result['filter'] );
		} else {
			$this->assertFalse( isset ( $result['filter'] ) );
		}
	}

	/** @dataProvider provideICUFoldingFilters */
	public function testICUFolding( array $input, array $expected ) {
		$config = new HashSearchConfig( ['CirrusSearchUseIcuFolding' => 'yes' ] );
		$plugins = ['extra', 'analysis-icu'];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config );
		$result = $builder->enableICUFolding( $input );
		$this->assertEquals( $expected['analyzer'], $result['analyzer'] );

		// test BC code
		$config = new HashSearchConfig( ['CirrusSearchUseIcuFolding' => true ] );
		$plugins = ['extra', 'analysis-icu'];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config );
		$this->assertTrue( $builder->isIcuFolding() );
		$this->assertEquals( $expected['analyzer'], $result['analyzer'] );

		// Test that we can force disabling ICU folding
		$config = new HashSearchConfig( ['CirrusSearchUseIcuFolding' => 'no' ] );
		$plugins = ['extra', 'analysis-icu'];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config );
		$this->assertFalse( $builder->isIcuFolding() );

		// Test that ICU folding cannot be enable without the required plugins
		$config = new HashSearchConfig( ['CirrusSearchUseIcuFolding' => 'yes' ] );
		$plugins = ['analysis-icu'];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config );
		$this->assertFalse( $builder->isIcuFolding() );
	}

	public static function provideASCIIFoldingFilters() {
		return [
			'only custom is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => ['asciifolding_preserve']
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => ['asciifolding_preserve']
						]
					],
				],
			],
			'only asciifolding_preserve is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => ['asciifolding']
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => ['asciifolding']
						],
					],
				],
			],
			'dedup filter is appended after asciifolding_preserve' => [
				[
					'filter' => [
						'asciifolding_preserve' => [
							'type' => 'asciifolding',
							'preserve_original' => true,
						],
					],
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => [
								'asciifolding_preserve',
								'french_stemmer',
							],
						],
					],
				],
				[
					'filter' => [
						'asciifolding_preserve' => [
							'type' => 'asciifolding',
							'preserve_original' => true,
						],
						'dedup_asciifolding' => [
							'type' => 'unique',
							'only_on_same_position' => true,
						],
					],
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => [
								'asciifolding_preserve',
								'dedup_asciifolding',
								'french_stemmer',
							],
						],
					],
				],
			],
		];
	}

	public static function provideICUFoldingFilters() {
		return [
			'only custom is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => ['asciifolding']
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => ['asciifolding']
						]
					],
				],
			],
			'simple' => [
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'icu_normalizer',
								'asciifolding',
								'kstem'
							],
						],
					],
				],
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'icu_normalizer',
								'icu_folding',
								'kstem'
							],
						],
					],
				],
			],
			'preserve' => [
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'icu_normalizer',
								'asciifolding_preserve',
								'kstem'
							],
						],
					],
				],
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'icu_normalizer',
								'preserve_original_recorder',
								'icu_folding',
								'preserve_original',
								'kstem'
							],
						],
					],
				],
			],
			'preserve no lowercase before' => [
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
								'asciifolding_preserve',
								'icu_normalizer',
								'kstem'
							],
						],
					],
				],
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
								'icu_nfkc_normalization',
								'preserve_original_recorder',
								'icu_folding',
								'preserve_original',
								'icu_normalizer',
								'kstem'
							],
						],
					],
				],
			],
			'icu_folding is explicitly added to plain analyzers' => [
				[
					'analyzer' => [
						'random_analyzer' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
							],
						],
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
							],
						],
						'source_text_plain' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
								'icu_normalizer',
							],
						],
					],
				],
				[
					'analyzer' => [
						'random_analyzer' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
							],
						],
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
								'icu_nfkc_normalization',
								'preserve_original_recorder',
								'icu_folding',
								'preserve_original',
							],
						],
						'source_text_plain' => [
							'type' => 'custom',
							'filter' => [
								'random_filter',
								'icu_normalizer',
							],
						],
					],
				],
			],
		];
	}
}
