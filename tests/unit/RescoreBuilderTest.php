<?php

namespace CirrusSearch\Search;

use CirrusSearch\Test\HashSearchConfig;

class RescoreBuilderTest extends \PHPUnit_Framework_TestCase {
	public function testFunctionScoreDecorator() {
		$func = new FunctionScoreDecorator();
		$this->assertTrue( $func->isEmptyFunction() );

		$func->addWeightFunction( 2.0, new \Elastica\Filter\MatchAll() );
		$this->assertFalse( $func->isEmptyFunction() );

		$array = $func->toArray();
		$this->assertTrue( isset( $array['function_score'] ) );
		$this->assertEquals( 1, count( $array['function_score']['functions'] ) );

		$func = new FunctionScoreDecorator();
		$this->assertTrue( $func->isEmptyFunction() );
		$func->addFunction( 'foo_function', array() );
		$func->addFunction( 'foo_function', array() );
		$this->assertFalse( $func->isEmptyFunction() );
		$array = $func->toArray();
		$this->assertEquals( 2, count( $array['function_score']['functions'] ) );

		$func = new FunctionScoreDecorator();
		$this->assertTrue( $func->isEmptyFunction() );
		$func->addScriptScoreFunction( new \Elastica\Script("foo+2") );
		$this->assertFalse( $func->isEmptyFunction() );
		$array = $func->toArray();
		$this->assertEquals( 1, count( $array['function_score']['functions'] ) );
	}

	public function testPreferRecent() {
		$config = new HashSearchConfig( array() );
		$context = new SearchContext( $config, null );
		$builder = new PreferRecentFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertTrue( $fScore->isEmptyFunction() );

		$context->setPreferRecentOptions( 1, 0.6 );

		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
	}

	public function testLangWeight() {
		// Default user lang seems to be en with unit tests
		// Test that we generate 2 filters
		$config = new HashSearchConfig( array(
			'CirrusSearchLanguageWeight' => array(
				'user' => 2,
				'wiki' => 3,
			),
			'LanguageCode' => 'de'
		) );
		$context = new SearchContext( $config, null );
		$builder = new LangWeightFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertEquals( count( $array['function_score']['functions'] ), 2 );

		// Set cont lang as en to we generate only 1 filter
		$config = new HashSearchConfig( array(
			'CirrusSearchLanguageWeight' => array(
				'user' => 2,
				'wiki' => 3,
			),
			'LanguageCode' => 'en'
		) );

		$context = new SearchContext( $config, null );
		$builder = new LangWeightFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertEquals( count( $array['function_score']['functions'] ), 1 );

		// Test that we do not generate any filter is weight are not set.
		$config = new HashSearchConfig( array(
			'CirrusSearchLanguageWeight' => array(),
			'LanguageCode' => 'de'
		) );
		$context = new SearchContext( $config, null );
		$builder = new LangWeightFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertTrue( $fScore->isEmptyFunction() );
	}

	public function testBoostTemplates() {
		$config = new HashSearchConfig( array() );
		$context = new SearchContext( $config, null );
		$builder = new BoostTemplatesFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertTrue( $fScore->isEmptyFunction() );

		$context->setBoostTemplatesFromQuery( array( 'test' => 3.2 ) );
		$builder = new BoostTemplatesFunctionScoreBuilder( $context, 1 );
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
	}

	public function testCustomField() {
		$config = new HashSearchConfig( array() );
		$context = new SearchContext( $config, null );
		$profile = array(
			'field' => 'test',
			'factor' => 5,
			'modifier' => 'sqrt',
			'missing' => 1,
		);
		$builder = new CustomFieldFunctionScoreBuilder( $context, 1, $profile );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertTrue( isset( $array['function_score']['functions'][0]['field_value_factor'] ) );
		$this->assertEquals( $profile, $array['function_score']['functions'][0]['field_value_factor'] );
	}

	public function testScriptScore() {
		$config = new HashSearchConfig( array() );
		$context = new SearchContext( $config, null );
		$script = "sqrt( doc['incoming_links'].value )";
		$builder = new ScriptScoreFunctionScoreBuilder( $context, 2, $script );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertTrue( isset( $array['function_score']['functions'][0]['script_score'] ) );
		$this->assertEquals( $script, $array['function_score']['functions'][0]['script_score']['script'] );
		$this->assertEquals( 'expression', $array['function_score']['functions'][0]['script_score']['lang'] );
		$this->assertEquals( 2, $array['function_score']['functions'][0]['weight'] );
	}

	public function testBoostLinks() {
		$settings = array();
		$config = new HashSearchConfig( $settings );
		$context = new SearchContext( $config, null );
		$context->setBoostLinks( true );
		$builder = new IncomingLinksFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();

		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertTrue( isset( $array['function_score']['functions'][0] ) );
		$array = $array['function_score']['functions'][0];
		$this->assertTrue( isset( $array['field_value_factor'] ) );

		$context->setBoostLinks( false );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertTrue( $fScore->isEmptyFunction() );
	}

	public function testNamespacesBoost() {
		$settings = array(
			'CirrusSearchNamespaceWeights' => array(
				NS_MAIN => 2.5,
				NS_PROJECT => 1.3,
				NS_HELP => 3,
			),
			'CirrusSearchDefaultNamespaceWeight' => 0.2,
			'CirrusSearchTalkNamespaceWeight' => 0.25
		);
		$config = new HashSearchConfig( $settings );

		// 5 namespaces in the query generates 5 filters
		$context = new SearchContext( $config, array( NS_MAIN, NS_PROJECT, NS_HELP, NS_MEDIAWIKI, NS_TALK ) );
		$builder = new NamespacesFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertEquals( 5, count( $array['function_score']['functions'] ) );

		// With a single namespace the function score is empty
		$context->setNamespaces( array( 0 ) );
		$fScore = new FunctionScoreDecorator();
		$this->assertTrue( $fScore->isEmptyFunction() );

		// with 2 namespaces we have 2 functions
		$context->setNamespaces( array( NS_MAIN, NS_HELP ) );
		$builder = new NamespacesFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertEquals( 2, count( $array['function_score']['functions'] ) );

		// Test that 2 similar boosts are flattened into the same filter
		$settings = array(
			'CirrusSearchNamespaceWeights' => array(
				NS_MAIN => 2,
				NS_PROJECT => 2,
				NS_HELP => 3,
			),
		);
		$config = new HashSearchConfig( $settings );
		$context = new SearchContext( $config, array( NS_MAIN, NS_PROJECT, NS_HELP ) );
		$builder = new NamespacesFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertEquals( 2, count( $array['function_score']['functions'] ) );

		// Test that a weigth to 1 is ignored
		$settings = array(
			'CirrusSearchNamespaceWeights' => array(
				NS_MAIN => 2,
				NS_PROJECT => 2,
				NS_HELP => 1,
			),
		);
		$config = new HashSearchConfig( $settings );
		$context = new SearchContext( $config, array( NS_MAIN, NS_PROJECT, NS_HELP ) );
		$builder = new NamespacesFunctionScoreBuilder( $context, 1 );
		$fScore = new FunctionScoreDecorator();
		$builder->append( $fScore );
		$this->assertFalse( $fScore->isEmptyFunction() );
		$array = $fScore->toArray();
		$this->assertEquals( 1, count( $array['function_score']['functions'] ) );
	}

	/**
	 * @dataProvider provideRescoreProfilesWithFallback
	 */
	public function testFallbackProfile( $settings, $namespaces, $expectedFunctionCount ) {
		$config = new HashSearchConfig( $settings );

		$context = new SearchContext( $config, $namespaces );
		$context->setBoostLinks( true );
		$context->setBoostTemplatesFromQuery( array( 'Good' => 1.3 ) );
		$builder = new RescoreBuilder( $context, $config->get( 'CirrusSearchRescoreProfile' ) );
		$rescore = $builder->build();
		$array = $rescore[0]['query']['rescore_query'];
		$array = $array->toArray();
		$this->assertEquals( $expectedFunctionCount, count( $array['function_score']['functions'] ) );
	}

	public static function provideRescoreProfilesWithFallback() {
		$defaultChain = array(
			'functions' => array(
				array( 'type' => 'boostlinks' )
			)
		);
		$fullChain = array(
			'functions' => array(
				array( 'type' => 'boostlinks' ),
				array( 'type' => 'templates' )
			)
		);
		$profile = array(
			'ContentNamespaces' => array( 1, 2 ),
			'CirrusSearchRescoreProfiles' => array(
				'full' => array(
					'supported_namespaces' => array( 0, 1 ),
					'fallback_profile' => 'default',
					'rescore' => array(
						array(
							'window' => 123,
							'type' => 'function_score',
							'function_chain' => 'full',
						)
					)
				),
				'content' => array(
					'supported_namespaces' => 'content',
					'fallback_profile' => 'default',
					'rescore' => array(
						array(
							'window' => 123,
							'type' => 'function_score',
							'function_chain' => 'full',
						)
					)
				),
				'default' => array(
					'supported_namespaces' => 'all',
					'rescore' => array(
						array(
							'window' => 123,
							'type' => 'function_score',
							'function_chain' => 'default',
						)
					)
				)
			),
			'CirrusSearchRescoreFunctionScoreChains' => array(
				'full' => $fullChain,
				'default' => $defaultChain
			)
		);
		return array(
			'No fallback' => array(
				$profile + array( 'CirrusSearchRescoreProfile' => 'full' ),
				array( 0 ),
				2
			),
			'No fallback multi namespace' => array(
				$profile + array( 'CirrusSearchRescoreProfile' => 'full' ),
				array( 0, 1 ),
				2
			),
			'No fallback content ns' => array(
				$profile + array( 'CirrusSearchRescoreProfile' => 'content' ),
				array( 1, 2 ),
				2
			),
			'Fallback content ns' => array(
				$profile + array( 'CirrusSearchRescoreProfile' => 'content' ),
				array( 0, 2 ),
				1
			),
			'Fallback with multiple namespace' => array(
				$profile + array( 'CirrusSearchRescoreProfile' => 'full' ),
				array( 0, 2 ),
				1
			),
			'Fallback null ns' => array(
				$profile + array( 'CirrusSearchRescoreProfile' => 'full' ),
				null,
				1
			),
		);
	}
	/**
	 * @dataProvider provideRescoreProfilesWithWindowSize
	 */
	public function testWindowSizeOverride( $settings, $expected ) {
		$config = new HashSearchConfig( $settings );

		$context = new SearchContext( $config, null );
		$context->setBoostLinks( true );
		$builder = new RescoreBuilder( $context, $config->getElement( 'CirrusSearchRescoreProfiles', 'default' ) );
		$rescore = $builder->build();
		$this->assertEquals( $expected, $rescore[0]['window_size'] );
	}

	public static function provideRescoreProfilesWithWindowSize() {
		$testChain = array(
			'functions' => array( array( 'type' => 'boostlinks' ) )
		);
		return array(
			'Overridden' => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 'all',
							'rescore' => array(
								array(
									'window' => 123,
									'window_size_override' => 'CirrusSearchOverrideWindow',
									'type' => 'function_score',
									'function_chain' => 'test',
								)
							)
						)
					),
					'CirrusSearchOverrideWindow' => 321,
					'CirrusSearchRescoreFunctionScoreChains' => array(
						'test' => $testChain
					)
				),
				321
			),
			'Overridden with missing config' => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 'all',
							'rescore' => array(
								array(
									'window' => 123,
									'window_size_override' => 'CirrusSearchOverrideWindow',
									'type' => 'function_score',
									'function_chain' => 'test',
								)
							)
						)
					),
					'CirrusSearchRescoreFunctionScoreChains' => array(
						'test' => $testChain
					)
				),
				123
			),
			'Not overridden' => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 'all',
							'rescore' => array(
								array(
									'window' => 123,
									'type' => 'function_score',
									'function_chain' => 'test',
								)
							)
						)
					),
					'CirrusSearchRescoreFunctionScoreChains' => array(
						'test' => $testChain
					)
				),
				123
			),
		);
	}
	/**
	 * @expectedException \CirrusSearch\Search\InvalidRescoreProfileException
	 * @dataProvider provideInvalidRescoreProfiles
	 */
	public function testBadRescoreProfile( $settings ) {
		$config = new HashSearchConfig( $settings );

		$context = new SearchContext( $config, null );
		$builder = new RescoreBuilder( $context, $config->getElement( 'CirrusSearchRescoreProfiles', 'default' ) );
		$builder->build();
	}

	public static function provideInvalidRescoreProfiles() {
		return array(
			'Unsupported rescore query type' => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 'all',
							'rescore' => array(
								array(
									'window' => 123,
									'type' => 'foobar',
								)
							)
						)
					),
				),
			),
			"Invalid rescore profile: supported_namespaces should be 'all' or an array of namespaces" => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 1,
						)
					),
				),
			),
			"Invalid rescore profile: fallback_profile is mandatory" => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => array( 0 ),
						)
					),
				),
			),
			"Unknown fallback profile" => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => array( 0 ),
							'fallback_profile' => 'missing',
						)
					),
				),
			),
			"Fallback profile must support all namespaces" => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => array( 0 ),
							'fallback_profile' => 'fallback',
						),
						'fallback' => array(
							'supported_namespaces' => array( 3 ),
						)
					),
				),
			),
			"Unknown rescore function chain" => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 'all',
							'rescore' => array(
								array(
									'window' => 123,
									'type' => 'function_score',
									'function_chain' => 'test',
								),
								array(
									'window' => 123,
									'type' => 'function_score',
									'function_chain' => 'test_missing',
								)
							)
						),
					),
					'CirrusSearchRescoreFunctionScoreChains' => array(
						'test' => array()
					)
				),
			),
			"Invalid function chain (none defined)" => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 'all',
							'rescore' => array(
								array(
									'window' => 123,
									'type' => 'function_score',
									'function_chain' => 'test',
								),
							)
						),
					),
					'CirrusSearchRescoreFunctionScoreChains' => array(
						'test' => array( )
					)
				),
			),
			"Invalid function score type" => array(
				array(
					'CirrusSearchRescoreProfiles' => array(
						'default' => array(
							'supported_namespaces' => 'all',
							'rescore' => array(
								array(
									'window' => 123,
									'type' => 'function_score',
									'function_chain' => 'test',
								),
							)
						),
					),
					'CirrusSearchRescoreFunctionScoreChains' => array(
						'test' => array( 'functions' => array( array( 'type' => 'foobar' ) ) )
					)
				),
			),
		);
	}
}
