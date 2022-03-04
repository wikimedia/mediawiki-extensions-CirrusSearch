<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\AnalyzerBuilder;
use Normalizer;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Maintenance\AnalysisConfigBuilder
 * @covers \CirrusSearch\Maintenance\AnalyzerBuilder
 */
class AnalysisConfigBuilderTest extends CirrusTestCase {

	/** @dataProvider provideASCIIFoldingFilters */
	public function testASCIIFoldingFix( array $input, array $expected ) {
		$config = $this->buildConfig( [] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$result = $builder->fixAsciiFolding( $input );

		$this->assertEquals( $expected[ 'analyzer' ], $result[ 'analyzer' ] );
		if ( isset( $expected[ 'filter' ] ) ) {
			$this->assertEquals( $expected[ 'filter' ], $result[ 'filter' ] );
		} else {
			$this->assertFalse( isset( $result[ 'filter' ] ) );
		}
	}

	/** @dataProvider provideICUFoldingFilters */
	public function testICUFolding( array $input, array $expected ) {
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => 'yes' ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'unknown_language', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$result = $builder->enableICUFolding( $input, 'unknown_language' );
		$this->assertEquals( $expected[ 'analyzer' ], $result[ 'analyzer' ] );
		$this->assertTrue( $builder->shouldActivateIcuFolding( 'unknown_language' ) );
		$this->assertTrue( $builder->shouldActivateIcuFolding( 'simple' ) );
		$this->assertTrue( $builder->isIcuAvailable() );

		// Test default
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => 'default' ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$result = $builder->enableICUFolding( $input, 'unknown_language' );
		$this->assertEquals( $expected[ 'analyzer' ], $result[ 'analyzer' ] );
		$this->assertFalse( $builder->shouldActivateIcuFolding( 'unknown_language' ) );
		$this->assertTrue( $builder->shouldActivateIcuFolding( 'simple' ) );

		// test BC code: true = 'yes'
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => true ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$this->assertTrue( $builder->shouldActivateIcuFolding( 'unknown_language' ) );
		$this->assertTrue( $builder->shouldActivateIcuFolding( 'en' ) );
		$this->assertEquals( $expected[ 'analyzer' ], $result[ 'analyzer' ] );

		// Test that we can force disabling ICU folding
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => 'no' ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$this->assertFalse( $builder->shouldActivateIcuFolding( 'en' ) );
		$this->assertFalse( $builder->shouldActivateIcuFolding( 'simple' ) );

		// test BC code: false = 'no'
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => false ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$this->assertFalse( $builder->shouldActivateIcuFolding( 'unknown_language' ) );
		$this->assertFalse( $builder->shouldActivateIcuFolding( 'en' ) );

		// Test that ICU folding cannot be enable without the required plugins
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => 'yes' ] );
		$plugins = [ 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$this->assertFalse( $builder->shouldActivateIcuFolding( 'en' ) );
		$this->assertFalse( $builder->shouldActivateIcuFolding( 'simple' ) );
	}

	/** @dataProvider provideICUTokenizer */
	public function testICUTokenizer( array $input, array $expected ) {
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuTokenizer' => 'yes' ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$result = $builder->enableICUTokenizer( $input );
		$this->assertTrue( $builder->shouldActivateIcuTokenization( 'en' ) );
		$this->assertTrue( $builder->shouldActivateIcuTokenization( 'bo' ) );
		$this->assertEquals( $expected[ 'analyzer' ], $result[ 'analyzer' ] );

		$config = $this->buildConfig( [ 'CirrusSearchUseIcuTokenizer' => 'no' ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$this->assertFalse( $builder->shouldActivateIcuTokenization( 'en' ) );
		$this->assertFalse( $builder->shouldActivateIcuTokenization( 'bo' ) );

		$config = $this->buildConfig( [ 'CirrusSearchUseIcuTokenizer' => 'default' ] );
		$plugins = [ 'extra', 'analysis-icu' ];
		$builder = new AnalysisConfigBuilder( 'bo', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$this->assertFalse( $builder->shouldActivateIcuTokenization( 'en' ) );
		$this->assertTrue( $builder->shouldActivateIcuTokenization( 'bo' ) );
	}

	/** @dataProvider provideHomoglyphPluginFilters */
	public function testHomoglyphPluginOrdering( array $input, array $expected ) {
		$config = $this->buildConfig( [] );
		$plugins = [ 'extra', 'extra-analysis-homoglyph' ];
		$builder = new AnalysisConfigBuilder( 'xx', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$builder->homoglyphIncompatibleFilters = [ 'badfilter1', 'badfilter2' ];
		$result = $builder->enableHomoglyphPlugin( $input, 'xx' );

		$this->assertEquals( $expected[ 'analyzer' ], $result[ 'analyzer' ] );
		if ( isset( $expected[ 'filter' ] ) ) {
			$this->assertEquals( $expected[ 'filter' ], $result[ 'filter' ] );
		} else {
			$this->assertFalse( isset( $result[ 'filter' ] ) );
		}

		// disable homoglyphs for language 'xx'
		$builder->homoglyphPluginDenyList = [ 'xx' => 'true' ];
		$result = $builder->enableHomoglyphPlugin( $input, 'xx' );
		$this->assertEquals( $input[ 'analyzer' ], $result[ 'analyzer' ] );
	}

	public static function provideASCIIFoldingFilters() {
		return [
			'ascii folding: only custom is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => [ 'asciifolding_preserve' ]
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => [ 'asciifolding_preserve' ]
						]
					],
				],
			],
			'only asciifolding_preserve is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => [ 'asciifolding' ]
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => [ 'asciifolding' ]
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
			'ascii folding: config without filter' => [
				// cover some defective corner cases
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
						],
					],
				],
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
						],
					],
				],
			],
		];
	}

	public static function provideICUFoldingFilters() {
		return [
			'icu folding: only custom is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => [ 'asciifolding' ]
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => [ 'asciifolding' ]
						]
					],
				],
			],
			'simple folding' => [
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
								'remove_empty',
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
								'remove_empty',
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
								'remove_empty',
								'icu_normalizer',
								'kstem'
							],
						],
					],
				],
			],
			'icu folding: config without filter' => [
				// cover some defective corner cases
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
						],
					],
				],
				[
					'analyzer' => [
						'plain' => [
							'type' => 'custom',
							'filter' => [
								'icu_nfkc_normalization',
								'preserve_original_recorder',
								'icu_folding',
								'preserve_original',
								'remove_empty',
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
								'remove_empty',
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

	public static function provideICUTokenizer() {
		return [
			'icu tokenizer: only custom is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => [ 'random' ]
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => [ 'random' ]
						]
					],
				],
			],
			'only tokenizer is updated' => [
				[
					'analyzer' => [
						'chinese' => [
							'type' => 'custom',
							'tokenizer' => 'standard',
							'filter' => [ 'random' ]
						]
					],
				],
				[
					'analyzer' => [
						'chinese' => [
							'type' => 'custom',
							'tokenizer' => 'icu_tokenizer',
							'filter' => [ 'random' ]
						]
					],
				],
			],
		];
	}

	public static function provideHomoglyphPluginFilters() {
		return [
			'homoglyph should be first' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'random_filter', 'filter2' ]
						],
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'filter2', 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'homoglyph_norm', 'random_filter', 'filter2' ]
						],
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'homoglyph_norm', 'filter2', 'random_filter' ]
						],
					],
				],
			],

			'homoglyph after bad filter' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'random_filter', 'badfilter2' ]
						],
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'badfilter2', 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'random_filter', 'badfilter2', 'homoglyph_norm' ]
						],
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'badfilter2', 'homoglyph_norm', 'random_filter' ]
						],
					],
				],
			],

			'homoglyph after multiple or duplicate bad filters' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'badfilter1', 'random_filter', 'badfilter1' ]
						],
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'badfilter2', 'badfilter1', 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'badfilter1', 'random_filter', 'badfilter1',
											'homoglyph_norm' ]
						],
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'badfilter2', 'badfilter1', 'homoglyph_norm',
											'random_filter' ]
						],
					],
				],
			],

			'homoglyph: only custom is updated' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'french',
							'filter' => [ 'asciifolding_preserve' ]
						]
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'french',
							'filter' => [ 'asciifolding_preserve' ]
						]
					],
				],
			],

			'homoglyph: text only' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'homoglyph_norm', 'random_filter' ]
						]
					],
				],
			],

			'homoglyph: text_search only' => [
				[
					'analyzer' => [
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'homoglyph_norm', 'random_filter' ]
						]
					],
				],
			],

			'homoglyph: config without filter' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
						],
						'text_search' => [
							'type' => 'custom',
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'filter' => [ 'homoglyph_norm' ]
						],
						'text_search' => [
							'type' => 'custom',
							'filter' => [ 'homoglyph_norm' ]
						],
					],
				],
			],
		];
	}

	public function provideLanguageAnalysis() {
		$tests = [];
		foreach ( CirrusIntegrationTestCase::findFixtures( 'languageAnalysis/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$extraConfig = CirrusIntegrationTestCase::loadFixture( $testFile );
			if ( isset( $extraConfig[ 'LangCode' ] ) ) {
				$langCode = $extraConfig[ 'LangCode' ];
			} else {
				$langCode = $testName;
			}
			$expectedFile = dirname( $testFile ) . "/$testName.expected";
			if ( CirrusIntegrationTestCase::hasFixture( $expectedFile ) ) {
				$expected = CirrusIntegrationTestCase::loadFixture( $expectedFile );
			} else {
				$expected = $expectedFile;
			}
			$tests[ $testName ] = [ $expected, $langCode, $extraConfig ];
		}
		return $tests;
	}

	/**
	 * Test various language specific analysers against fixtures, to make
	 *  the results of generation obvious and tracked in git
	 *
	 * @dataProvider provideLanguageAnalysis
	 * @param mixed $expected
	 * @param string $langCode
	 * @param array $extraConfig
	 */
	public function testLanguageAnalysis( $expected, $langCode, array $extraConfig ) {
		$config = $this->buildConfig( $extraConfig );
		$plugins = [
			'analysis-stempel', 'analysis-kuromoji',
			'analysis-smartcn', 'analysis-hebrew',
			'analysis-ukrainian', 'analysis-stconvert',
			'extra-analysis-serbian', 'extra-analysis-slovak',
			'extra-analysis-esperanto', 'analysis-nori',
			'extra-analysis-homoglyph', 'extra-analysis-khmer',
		];
		$builder = new AnalysisConfigBuilder( $langCode, $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		if ( is_string( $expected ) ) {
			// generate fixture
			CirrusIntegrationTestCase::saveFixture( $expected, $builder->buildConfig() );
			$this->markTestSkipped( "Generated new fixture" );
		} else {
			$this->assertEquals( $expected, $builder->buildConfig() );

			// also verify that custom stop lists and patterns are in NFKC form
			if ( array_key_exists( 'filter', $builder->buildConfig() ) ) {
				foreach ( $builder->buildConfig()[ 'filter' ] as $filter ) {
					if ( array_key_exists( 'type', $filter ) ) {
						if ( $filter[ 'type' ] == 'stop'
								&& array_key_exists( 'stopwords', $filter )
								&& is_array( $filter[ 'stopwords' ] ) ) {
							foreach ( $filter[ 'stopwords' ] as $stopword ) {
								$this->assertEquals( $stopword,
									Normalizer::normalize( $stopword, Normalizer::FORM_KC ) );
							}
						}
						if ( $filter[ 'type' ] == 'pattern_replace'
								&& array_key_exists( 'pattern', $filter )
								) {
							$pat = $filter[ 'pattern' ];
							$this->assertEquals( $pat,
								Normalizer::normalize( $pat, Normalizer::FORM_KC ) );
						}
					}
				}
			}
		}
	}

	public function languageConfigDataProvider() {
		$emptyConfig = [
			'analyzer' => [],
			'filter' => [],
			'char_filter' => [],
			'tokenizer' => []
		];
		$allPlugins = [
			'extra',
			'extra-analysis',
			'analysis-icu',
			'analysis-stempel',
			'analysis-kuromoji',
			'analysis-smartcn',
			'analysis-hebrew',
			'analysis-ukrainian',
			'analysis-stconvert',
			'analysis-nori',
		];

		$reflACB = new \ReflectionClass( AnalysisConfigBuilder::class );

		return [
			"some languages" => [
				[ 'en', 'ru', 'es', 'de', 'zh', 'ko' ],
				$emptyConfig,
				$allPlugins,
				'en-ru-es-de-zh-ko',
			],
			// sv has custom icu_folding filter
			"en-zh-sv" => [
				[ 'en', 'zh', 'sv' ],
				$emptyConfig,
				$allPlugins,
				'en-zh-sv',
			],
			"with plugins" => [
				[ 'he', 'uk' ],
				$emptyConfig,
				$allPlugins,
				'he-uk',
			],
			"without language plugins" => [
				[ 'he', 'uk' ],
				$emptyConfig,
				[ 'extra', 'analysis-icu' ],
				'he-uk-nolang',
			],
			"without any plugins" => [
				[ 'he', 'uk' ],
				$emptyConfig,
				[],
				'he-uk-noplug',
			],
			"all default languages" => [
				[ 'ch', 'fy', 'kab', 'ti', 'xmf' ],
				$emptyConfig,
				[ 'extra', 'analysis-icu' ],
				'all_defaults',
			],
			"icu folding languages" => [
				array_keys( $reflACB->getDefaultProperties()[ 'languagesWithIcuFolding' ] ),
				$emptyConfig,
				[ 'extra', 'analysis-icu' ],
				'icu_folders',
			],
			"language-specific lowercasing" => [
				[ 'el', 'ga', 'tr' ],
				$emptyConfig,
				[ 'extra', 'analysis-icu' ],
				'custom_lowercase',
			],
		];
	}

	/**
	 * @param string[] $languages
	 * @param array $oldConfig
	 * @param string[] $plugins
	 * @param string $expectedConfig Filename with expected config
	 * @dataProvider languageConfigDataProvider
	 */
	public function testAnalysisConfig( $languages, $oldConfig, $plugins, $expectedConfig ) {
		// We use these static settings because we rely on tests in main
		// AnalysisConfigBuilderTest to handle variations
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => 'default' ] );

		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		$prevConfig = $oldConfig;
		$builder->buildLanguageConfigs( $oldConfig, $languages,
			[ 'plain', 'plain_search', 'text', 'text_search' ] );
		$oldConfig = $this->normalizeAnalysisConfig( $oldConfig );
		$expectedFile = "analyzer/$expectedConfig.expected";
		if ( CirrusIntegrationTestCase::hasFixture( $expectedFile ) ) {
			$expected = CirrusIntegrationTestCase::loadFixture( $expectedFile );
			$this->assertEquals( $expected, $oldConfig );
		} else {
			CirrusIntegrationTestCase::saveFixture( $expectedFile, $oldConfig );
			$this->markTestSkipped( "Generated new fixture" );
		}

		$oldConfig = $prevConfig;
		$builder->buildLanguageConfigs( $oldConfig, $languages,
			[ 'plain', 'plain_search' ] );
		$oldConfig = $this->normalizeAnalysisConfig( $oldConfig );
		$expectedFile = "analyzer/$expectedConfig.plain.expected";
		if ( CirrusIntegrationTestCase::hasFixture( $expectedFile ) ) {
			$expected = CirrusIntegrationTestCase::loadFixture( $expectedFile );
			$this->assertEquals( $expected, $oldConfig );
		} else {
			CirrusIntegrationTestCase::saveFixture( $expectedFile, $oldConfig );
			$this->markTestSkipped( "Generated new fixture" );
		}
	}

	private function buildConfig( array $configs ) {
		return $this->newHashSearchConfig( $configs + [ 'CirrusSearchSimilarityProfile' => 'default' ] );
	}

	/**
	 * Normalize analysis config for storage in fixture files
	 *
	 * The analysis config is a map from string to list of named elements, the order itself
	 * doesn't matter only the names. As such sort everything by key to give a consistent
	 * ordering in fixtures and avoid unnecessary fixture changes.
	 *
	 * @param array $config Elasticsearch analysis config
	 * @return array Normalized elasticsearch analysis config
	 */
	private function normalizeAnalysisConfig( array $config ) {
		foreach ( $config as $group => $items ) {
			foreach ( $items as $k => $v ) {
				if ( is_array( $v ) ) {
					ksort( $config[$group][$k] );
				}
			}
			ksort( $config[$group] );
		}
		ksort( $config );
		return $config;
	}

	public static function provideUnpackedOnlyMethods() {
		$functionsToTest = [ 'omitAsciifolding', 'omitDottedI', 'withAggressiveSplitting',
			'withAsciifoldingPreserve', 'withLightStemmer', 'withRemoveEmpty',
			'withWordBreakHelper' ];
		$ret = [];
		foreach ( $functionsToTest as $func ) {
			$ret[ $func ] = [ $func ];
		}
		return $ret;
	}

	/**
	 * @param string $name
	 * @dataProvider provideUnpackedOnlyMethods
	 */
	public function testUnpackedOnlyMethods( string $name ) {
		$config = [];

		// Should work if called after withUnpackedAnalyzer()
		$config = ( new AnalyzerBuilder( 'xx' ) )->
				withUnpackedAnalyzer()->
				$name()->
				build( $config );

		// Should fail if called before withUnpackedAnalyzer()
		$this->expectException( \ConfigException::class );
		$config = ( new AnalyzerBuilder( 'xx' ) )->
				$name()->
				withUnpackedAnalyzer()->
				build( $config );

		// Should fail if called without withUnpackedAnalyzer()
		$this->expectException( \ConfigException::class );
		$config = ( new AnalyzerBuilder( 'xx' ) )->
				$name()->
				build( $config );
	}

	public function testInsertFiltersBefore() {
		$config = [];
		$expected = [ 'xx_FIRST', 'xx_pre_pre', 'xx_pre', 'lowercase', 'xx_stop',
					  'xx_pre_stem', 'xx_stemmer', 'xx_pre_post', 'xx_post' ];

		// Build up the "expected" analysis chain filters, in a slightly silly way
		$config = ( new AnalyzerBuilder( 'xx' ) )->
				withUnpackedAnalyzer()->
				omitAsciifolding()->
				insertFiltersBefore( 'xx_stemmer', [ 'xx_pre_stem' ] )->
				insertFiltersBefore( AnalyzerBuilder::PREPEND, [ 'xx_pre' ] )->
				insertFiltersBefore( AnalyzerBuilder::APPEND, [ 'xx_post' ] )->
				insertFiltersBefore( 'xx_pre', [ 'xx_pre_pre' ] )->
				insertFiltersBefore( 'xx_post', [ 'xx_pre_post' ] )->
				insertFiltersBefore( AnalyzerBuilder::PREPEND, [ 'xx_FIRST' ] )->
				build( $config );

		$this->assertEquals( $expected, $config[ 'analyzer' ][ 'text' ][ 'filter' ] );

		// Let's do it again, but in a different way (this is realistic)
		$config = ( new AnalyzerBuilder( 'xx' ) )->
				withUnpackedAnalyzer()->
				omitAsciifolding()->
				insertFiltersBefore( AnalyzerBuilder::PREPEND,
					[ 'xx_FIRST', 'xx_pre_pre', 'xx_pre' ] )->
				insertFiltersBefore( AnalyzerBuilder::APPEND,
					[ 'xx_pre_post', 'xx_post' ] )->
				insertFiltersBefore( 'xx_stemmer', [ 'xx_pre_stem' ] )->
				build( $config );

		$this->assertEquals( $expected, $config[ 'analyzer' ][ 'text' ][ 'filter' ] );

		// One more time... all over the place
		$config = ( new AnalyzerBuilder( 'xx' ) )->
				withUnpackedAnalyzer()->
				omitAsciifolding()->
				insertFiltersBefore( AnalyzerBuilder::PREPEND, [ 'xx_pre' ] )->
				insertFiltersBefore( AnalyzerBuilder::APPEND, [ 'xx_pre_post' ] )->
				insertFiltersBefore( 'xx_pre', [ 'xx_pre_pre' ] )->
				insertFiltersBefore( AnalyzerBuilder::PREPEND, [ 'xx_FIRST' ] )->
				insertFiltersBefore( 'xx_stemmer', [ 'xx_pre_stem' ] )->
				insertFiltersBefore( AnalyzerBuilder::APPEND, [ 'xx_post' ] )->
				build( $config );

		$this->assertEquals( $expected, $config[ 'analyzer' ][ 'text' ][ 'filter' ] );
	}

}
