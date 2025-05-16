<?php

namespace CirrusSearch\Wikimedia;

use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Query\ArticlePredictionKeyword;
use MediaWiki\Config\ConfigFactory;

/**
 * @covers \CirrusSearch\Wikimedia\WeightedTagsHooks
 */
class WeightedTagsHooksTest extends CirrusTestCase {

	private function newHookHandler( array $config ): WeightedTagsHooks {
		return new WeightedTagsHooks( $this->newHashSearchConfig( $config ) );
	}

	public function testFactory() {
		$configFactory = new ConfigFactory();
		$config = $this->newHashSearchConfig();
		$configFactory->register( 'CirrusSearch', $config );
		$handler = WeightedTagsHooks::create( $configFactory );
		$this->assertSame( $config, $handler->getConfig() );
	}

	public function testConfigureWeightedTagsSimilarity() {
		$sim = [];
		$maxScore = 17389;
		$handler = $this->newHookHandler( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::BUILD_OPTION => true,
					WeightedTagsHooks::MAX_SCORE_OPTION => $maxScore,
					]
				]
		] );
		$handler->onCirrusSearchSimilarityConfig( $sim );
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_SIMILARITY, $sim );
		$this->assertStringContainsString( $maxScore,
			$sim[WeightedTagsHooks::FIELD_SIMILARITY]['script']['source'] );
	}

	public function testConfigureWeightedTagsSimilarityDisabled() {
		$handler = $this->newHookHandler( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::BUILD_OPTION => false,
				]
			]
		] );
		$sim = [];
		$handler->onCirrusSearchSimilarityConfig( $sim );
		$this->assertSame( [], $sim );
	}

	public function testConfigureWeightedTagsFieldMapping() {
		$handler = $this->newHookHandler( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::BUILD_OPTION => true,
				]
			]
		] );
		$searchEngine = $this->createNoOpMock( CirrusSearch::class );
		/**
		 * @var \SearchIndexField $fields
		 */
		$fields = [];
		$handler->onSearchIndexFields( $fields, $searchEngine );
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_NAME, $fields );
		$field = $fields[WeightedTagsHooks::FIELD_NAME];
		$this->assertInstanceOf( WeightedTags::class, $field );
		$mapping = $field->getMapping( $searchEngine );
		$this->assertSame( 'text', $mapping['type'] );
		$this->assertSame( WeightedTagsHooks::FIELD_SEARCH_ANALYZER, $mapping['search_analyzer'] );
		$this->assertSame( WeightedTagsHooks::FIELD_INDEX_ANALYZER, $mapping['analyzer'] );
		$this->assertSame( WeightedTagsHooks::FIELD_SIMILARITY, $mapping['similarity'] );
	}

	public function testConfigureWeightedTagsFieldMappingDisabled() {
		$handler = $this->newHookHandler( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::BUILD_OPTION => false,
				]
			]
		] );
		$searchEngine = $this->createNoOpMock( CirrusSearch::class );
		$fields = [];
		$handler->onSearchIndexFields( $fields, $searchEngine );
		$this->assertSame( [], $fields );
	}

	public function testConfigureWeightedTagsFieldAnalysis() {
		$maxScore = 41755;
		$handler = $this->newHookHandler( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::BUILD_OPTION => true,
					WeightedTagsHooks::MAX_SCORE_OPTION => $maxScore,
				]
			]
		] );

		$configBuilder = $this->createNoOpMock( AnalysisConfigBuilder::class );
		$analysisConfig = [];
		$handler->onCirrusSearchAnalysisConfig( $analysisConfig, $configBuilder );
		$this->assertArrayHasKey( 'analyzer', $analysisConfig );
		$this->assertArrayHasKey( 'filter', $analysisConfig );
		$analyzers = $analysisConfig['analyzer'];
		$filters = $analysisConfig['filter'];
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_INDEX_ANALYZER, $analyzers );
		$this->assertArrayHasKey( 'weighted_tags_term_freq', $filters );
		$this->assertSame( $maxScore, $filters['weighted_tags_term_freq']['max_tf'] );
	}

	public function testConfigureWeightedTagsFieldAnalysisDisabled() {
		$handler = $this->newHookHandler( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::BUILD_OPTION => false,
				]
			]
		] );
		$configBuilder = $this->createNoOpMock( AnalysisConfigBuilder::class );
		$analysisConfig = [];
		$handler->onCirrusSearchAnalysisConfig( $analysisConfig, $configBuilder );
		$this->assertSame( [], $analysisConfig );
	}

	public function testOnCirrusSearchAddQueryFeatures() {
		$config = new HashSearchConfig( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::USE_OPTION => false,
				],
			],
		] );
		$handler = new WeightedTagsHooks( $config );
		$extraFeatures = [];
		$handler->onCirrusSearchAddQueryFeatures( $config, $extraFeatures );
		$this->assertSame( [], $extraFeatures );

		$config = new HashSearchConfig( [
			WeightedTagsHooks::WMF_EXTRA_FEATURES => [
				WeightedTagsHooks::CONFIG_OPTIONS => [
					WeightedTagsHooks::USE_OPTION => true,
				],
			],
		] );
		$handler = new WeightedTagsHooks( $config );
		$handler->onCirrusSearchAddQueryFeatures( $config, $extraFeatures );
		$this->assertNotEmpty( $extraFeatures );
		$this->assertInstanceOf( ArticlePredictionKeyword::class, $extraFeatures[0] );
	}
}
