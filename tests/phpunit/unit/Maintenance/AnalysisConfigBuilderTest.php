<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\AnalyzerBuilder;
use CirrusSearch\Maintenance\GlobalCustomFilter;
use MediaWiki\Config\ConfigException;
use Normalizer;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Maintenance\AnalysisConfigBuilder
 * @covers \CirrusSearch\Maintenance\AnalyzerBuilder
 * @covers \CirrusSearch\Maintenance\GlobalCustomFilter
 */
class AnalysisConfigBuilderTest extends CirrusTestCase {

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

	/** @dataProvider provideHomoglyphPluginFilters */
	public function testHomoglyphPluginOrdering( array $input, array $expected ) {
		$config = $this->buildConfig( [] );
		$plugins = [ 'extra', 'extra-analysis-homoglyph' ];
		$builder = new AnalysisConfigBuilder( 'xx', $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );

		// keep things simple and only enable homoglyph_norm for testing
		// specify badfilter[12] as incompatible
		$builder->globalCustomFilters = [
			'homoglyph_norm' => ( new GlobalCustomFilter( 'filter' ) )->
			setRequiredPlugins( [ 'extra-analysis-homoglyph' ] )->
			setMustFollowFilters( [ 'badfilter1', 'badfilter2' ] ),
		];

		$result = $builder->enableGlobalCustomFilters( $input, 'xx' );

		$this->assertEquals( $expected[ 'analyzer' ], $result[ 'analyzer' ] );
		if ( isset( $expected[ 'filter' ] ) ) {
			$this->assertEquals( $expected[ 'filter' ], $result[ 'filter' ] );
		} else {
			$this->assertFalse( isset( $result[ 'filter' ] ) );
		}

		// disable homoglyphs for text_search
		$builder->globalCustomFilters[ 'homoglyph_norm' ]->setApplyToAnalyzers( [ 'text' ] );
		$this->assertEquals( [ 'text' ],
			$builder->globalCustomFilters[ 'homoglyph_norm' ]->getApplyToAnalyzers() );

		$result = $builder->enableGlobalCustomFilters( $input, 'xx' );
		if ( array_key_exists( 'text', $result[ 'analyzer' ] ) ) {
			$this->assertEquals( $expected[ 'analyzer' ][ 'text' ], $result[ 'analyzer' ][ 'text' ] );
		}
		if ( array_key_exists( 'text_search', $result[ 'analyzer' ] ) ) {
			$this->assertEquals( $input[ 'analyzer' ][ 'text_search' ],
				$result[ 'analyzer' ][ 'text_search' ] );
		}

		// re-enable homoglyphs for text & text_search
		// disable homoglyphs for language 'xx'
		$builder->globalCustomFilters[ 'homoglyph_norm' ]->setApplyToAnalyzers( [ 'text', 'text_search' ] );
		$this->assertEquals( [ 'text', 'text_search' ],
			$builder->globalCustomFilters[ 'homoglyph_norm' ]->getApplyToAnalyzers() );

		$builder->globalCustomFilters[ 'homoglyph_norm' ]->setLanguageDenyList( [ 'xx' ] );
		$result = $builder->enableGlobalCustomFilters( $input, 'xx' );
		$this->assertEquals( $input[ 'analyzer' ], $result[ 'analyzer' ] );
	}

	public static function provideLanguageAnalysis() {
		foreach ( CirrusIntegrationTestCase::findFixtures( 'languageAnalysis/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$extraConfig = CirrusIntegrationTestCase::loadFixture( $testFile );
			$langCode = $extraConfig['LangCode'] ?? $testName;
			$expectedFile = dirname( $testFile ) . "/$testName.expected";
			if ( CirrusIntegrationTestCase::hasFixture( $expectedFile ) ) {
				$expected = CirrusIntegrationTestCase::loadFixture( $expectedFile );
			} else {
				$expected = $expectedFile;
			}
			yield $testName => [ $expected, $langCode, $extraConfig ];
		}
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
			'extra-analysis-turkish', 'extra-analysis-textify',
		];
		if ( array_key_exists( 'withPlugins', $extraConfig ) ) {
			array_push( $plugins, ...$extraConfig[ 'withPlugins' ] );
		}

		$builder = new AnalysisConfigBuilder( $langCode, $plugins, $config, $this->createCirrusSearchHookRunner( [] ) );
		if ( is_string( $expected ) ) {
			// generate fixture
			CirrusIntegrationTestCase::saveAnalysisFixture( $expected, $builder->buildConfig() );
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

	public static function languageConfigDataProvider() {
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
			"en-ru-es-de-zh-ko" => [
				[ 'en', 'ru', 'es', 'de', 'zh', 'ko' ],
				$emptyConfig,
				$allPlugins,
				'en-ru-es-de-zh-ko',
			],
			// same as above, but with textify plugin
			"en-ru-es-de-zh-ko textify" => [
				[ 'en', 'ru', 'es', 'de', 'zh', 'ko' ],
				$emptyConfig,
				array_merge( $allPlugins, [ 'extra-analysis-textify' ] ),
				'en-ru-es-de-zh-ko_textify',
			],
			// sv has custom icu_folding filter
			"en-zh-sv" => [
				[ 'en', 'zh', 'sv' ],
				$emptyConfig,
				$allPlugins,
				'en-zh-sv',
			],
			"he-uk with plugins" => [
				[ 'he', 'uk' ],
				$emptyConfig,
				$allPlugins,
				'he-uk',
			],
			"he-uk without language plugins" => [
				[ 'he', 'uk' ],
				$emptyConfig,
				[ 'extra', 'analysis-icu' ],
				'he-uk-nolang',
			],
			"he-uk without any plugins" => [
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
			"icu tokenizing languages" => [
				array_keys( $reflACB->getDefaultProperties()[ 'languagesWithIcuTokenization' ] ),
				$emptyConfig,
				[ 'extra', 'analysis-icu' ],
				'icu_tokenizers',
			],
			"language-specific lowercasing" => [
				[ 'el', 'ga', 'tr', 'az', 'crh', 'gag', 'kk', 'tt' ],
				$emptyConfig,
				[ 'extra', 'analysis-icu' ],
				'custom_lowercase',
			],
		];
	}

	/** @dataProvider languageConfigDataProvider */
	public function testAnalysisConfig( $languages, $oldConfig, $plugins, $expectedConfig ) {
		// We use these static settings because we rely on tests in main
		// AnalysisConfigBuilderTest to handle variations
		$config = $this->buildConfig( [ 'CirrusSearchUseIcuFolding' => 'default',
										'CirrusSearchUseIcuTokenizer' => 'default' ] );

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
			CirrusIntegrationTestCase::saveAnalysisFixture( $expectedFile, $oldConfig );
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
			CirrusIntegrationTestCase::saveAnalysisFixture( $expectedFile, $oldConfig );
			$this->markTestSkipped( "Generated new fixture" );
		}
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
		$functionsToTest = [ 'withLightStemmer', 'omitStemmer',
							 'withAsciifoldingPreserve', 'omitAsciifolding', 'withRemoveEmpty',
							 'withDecimalDigit' ];
		foreach ( $functionsToTest as $func ) {
			yield $func => [ $func ];
		}
	}

	/** @dataProvider provideUnpackedOnlyMethods */
	public function testUnpackedOnlyMethods( string $name ) {
		$config = [];

		// Should work if called after withUnpackedAnalyzer()
		$config = ( new AnalyzerBuilder( 'xx' ) )->
		withUnpackedAnalyzer()->
		$name()->
		build( $config );

		// Should fail if called before withUnpackedAnalyzer()
		$this->expectException( ConfigException::class );
		$config = ( new AnalyzerBuilder( 'xx' ) )->
		$name()->
		withUnpackedAnalyzer()->
		build( $config );

		// Should fail if called without withUnpackedAnalyzer()
		$this->expectException( ConfigException::class );
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

		// ... again with pre-/appendFilters()
		$config = ( new AnalyzerBuilder( 'xx' ) )->
		withUnpackedAnalyzer()->
		omitAsciifolding()->
		insertFiltersBefore( 'xx_stemmer', [ 'xx_pre_stem' ] )->
		prependFilters( [ 'xx_pre' ] )->
		appendFilters( [ 'xx_post' ] )->
		insertFiltersBefore( 'xx_pre', [ 'xx_pre_pre' ] )->
		insertFiltersBefore( 'xx_post', [ 'xx_pre_post' ] )->
		prependFilters( [ 'xx_FIRST' ] )->
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

		// ... again with pre-/appendFilters()
		$config = ( new AnalyzerBuilder( 'xx' ) )->
		withUnpackedAnalyzer()->
		omitAsciifolding()->
		prependFilters( [ 'xx_FIRST', 'xx_pre_pre', 'xx_pre' ] )->
		appendFilters( [ 'xx_pre_post', 'xx_post' ] )->
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

	public static function provideGCFLangAllowAndDeny() {
		return [
			'xx' => [ 'xx', [ 'xx_only', 'not_yy' ] ],
			'yy' => [ 'yy', [] ],
			'zz' => [ 'zz', [ 'not_yy' ] ],
		];
	}

	/** @dataProvider provideGCFLangAllowAndDeny */
	public function testGCFLangAllowAndDeny( string $lang, array $expected ) {
		$gcf = array_reverse( [
			'xx_only' => ( new GlobalCustomFilter( 'filter' ) )->
			setLanguageAllowList( [ 'xx' ] ),

			'not_yy' => ( new GlobalCustomFilter( 'filter' ) )->
			setLanguageDenyList( [ 'yy' ] ),
		] );
		$builder = new AnalysisConfigBuilder( $lang, [], $this->buildConfig( [] ),
			$this->createCirrusSearchHookRunner( [] ) );
		$builder->globalCustomFilters = $gcf;
		$empty_analyzer = [ 'analyzer' => [ 'text' => [ 'type' => 'custom', 'filter' => [] ] ] ];

		$result = $builder->enableGlobalCustomFilters( $empty_analyzer, $lang );
		$this->assertEquals( $expected, $result[ 'analyzer' ][ 'text' ][ 'filter' ] );
	}

	public static function provideGCFDisallowedFilters() {
		return [
			'no disallowed filters' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'random_char_filter' ],
							'filter' => [ 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'no_bad_char_filt', 'no_bad_cross_char_filt', 'random_char_filter' ],
							'filter' => [ 'no_bad_filt', 'no_bad_cross_filt', 'random_filter' ]
						],
					],
				],
			],

			'disallowed token filter' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'random_char_filter' ],
							'filter' => [ 'bad_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'no_bad_char_filt', 'random_char_filter' ],
							'filter' => [ 'no_bad_cross_filt', 'bad_filter' ]
						],
					],
				],
			],

			'disallowed char filter' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'bad_char_filter' ],
							'filter' => [ 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'no_bad_cross_char_filt', 'bad_char_filter' ],
							'filter' => [ 'no_bad_filt', 'random_filter' ]
						],
					],
				],
			],

			'disallowed char and token filter' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'bad_char_filter' ],
							'filter' => [ 'bad_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'bad_char_filter' ],
							'filter' => [ 'bad_filter' ]
						],
					],
				],
			],

		];
	}

	/** @dataProvider provideGCFDisallowedFilters */
	public function testGCFDisallowedFilters( array $config, array $expected ) {
		$gcf = array_reverse( [
			// char_filter blocked by char_filter
			'no_bad_char_filt' => ( new GlobalCustomFilter( 'char_filter' ) )->
			setDisallowedCharFilters( [ 'bad_char_filter' ] ),

			// char_filter blocked by filter
			'no_bad_cross_char_filt' => ( new GlobalCustomFilter( 'char_filter' ) )->
			setDisallowedTokenFilters( [ 'bad_filter' ] ),

			// filter blocked by filter
			'no_bad_filt' => ( new GlobalCustomFilter( 'filter' ) )->
			setDisallowedTokenFilters( [ 'bad_filter' ] ),

			// filter blocked by char_filter
			'no_bad_cross_filt' => ( new GlobalCustomFilter( 'filter' ) )->
			setDisallowedCharFilters( [ 'bad_char_filter' ] ),
		] );

		$builder = new AnalysisConfigBuilder( 'xx', [], $this->buildConfig( [] ),
			$this->createCirrusSearchHookRunner( [] ) );
		$builder->globalCustomFilters = $gcf;

		$result = $builder->enableGlobalCustomFilters( $config, 'xx' );
		$this->assertEquals( $expected, $result );
	}

	public static function provideGCFBlockDuplicateFilters() {
		return [
			'no duplicates' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'random_char_filter' ],
							'filter' => [ 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'new_filter1', 'random_char_filter' ],
							'filter' => [ 'new_filter2', 'random_filter' ]
						],
					],
				],
			],

			'char filter duplicate' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'new_filter1' ],
							'filter' => [ 'random_filter' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'new_filter1' ],
							'filter' => [ 'new_filter2', 'random_filter' ]
						],
					],
				],
			],

			'token filter duplicate' => [
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'random_char_filter' ],
							'filter' => [ 'new_filter2' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'new_filter1', 'random_char_filter' ],
							'filter' => [ 'new_filter2' ]
						],
					],
				],
			],

			'terrible filter names' => [
				// char filters block char filters, tok filters block tok filters
				// filters of the other type with the same name do not block
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'new_filter2' ],
							'filter' => [ 'new_filter1' ]
						],
					],
				],
				[
					'analyzer' => [
						'text' => [
							'type' => 'custom',
							'char_filter' => [ 'new_filter1', 'new_filter2' ],
							'filter' => [ 'new_filter2', 'new_filter1' ]
						],
					],
				],
			],

		];
	}

	/** @dataProvider provideGCFBlockDuplicateFilters */
	public function testGCFBlockDuplicateFilters( array $config, array $expected ) {
		$gcf = array_reverse( [
			'new_filter1' => new GlobalCustomFilter( 'char_filter' ),

			'new_filter2' => new GlobalCustomFilter( 'filter' ),
		] );

		$builder = new AnalysisConfigBuilder( 'xx', [], $this->buildConfig( [] ),
			$this->createCirrusSearchHookRunner( [] ) );
		$builder->globalCustomFilters = $gcf;

		$result = $builder->enableGlobalCustomFilters( $config, 'xx' );
		$this->assertEquals( $expected, $result );
	}

	private function buildConfig( array $configs ) {
		return $this->newHashSearchConfig( $configs + [ 'CirrusSearchSimilarityProfile' => 'default' ] );
	}

}
