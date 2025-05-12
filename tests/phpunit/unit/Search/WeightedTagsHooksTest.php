<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Query\ArticlePredictionKeyword;
use CirrusSearch\Query\HasRecommendationFeature;
use MediaWiki\Config\ConfigFactory;

/**
 * @covers \CirrusSearch\Search\WeightedTagsHooks
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
			"CirrusSearchWeightedTags" => [
				'build' => true,
				'max_score' => $maxScore,
			],
		] );
		$handler->onCirrusSearchSimilarityConfig( $sim );
		$this->assertSimilarityRegistered( $sim, $maxScore );
	}

	public function testConfigureWeightedTagsSimilarityDisabled() {
		$handler = $this->newHookHandler( [
			"CirrusSearchWeightedTags" => [
				'build' => false,
			],
		] );
		$sim = [];
		$handler->onCirrusSearchSimilarityConfig( $sim );
		$this->assertSame( [], $sim );
	}

	public function testConfigureWeightedTagsFieldMapping() {
		$handler = $this->newHookHandler( [
			"CirrusSearchWeightedTags" => [
				'build' => true,
			],
		] );
		$searchEngine = $this->createNoOpMock( CirrusSearch::class );
		/**
		 * @var \SearchIndexField[] $fields
		 */
		$fields = [];
		$handler->onSearchIndexFields( $fields, $searchEngine );
		$this->assertSearchIndexFieldsRegistered( $fields, $searchEngine );
	}

	public function testConfigureWeightedTagsFieldMappingDisabled() {
		$handler = $this->newHookHandler( [
			"CirrusSearchWeightedTags" => [
				'build' => false,
			],
		] );
		$searchEngine = $this->createNoOpMock( CirrusSearch::class );
		$fields = [];
		$handler->onSearchIndexFields( $fields, $searchEngine );
		$this->assertSame( [], $fields );
	}

	public function testConfigureWeightedTagsFieldAnalysis() {
		$maxScore = 41755;
		$handler = $this->newHookHandler( [
			"CirrusSearchWeightedTags" => [
				'build' => true,
				'max_score' => $maxScore,
			],
		] );

		$configBuilder = $this->createNoOpMock( AnalysisConfigBuilder::class );
		$analysisConfig = [];
		$handler->onCirrusSearchAnalysisConfig( $analysisConfig, $configBuilder );
		$this->assertAnalysisConfigRegistered( $analysisConfig, $maxScore );
	}

	public function testConfigureWeightedTagsFieldAnalysisDisabled() {
		$handler = $this->newHookHandler( [
			"CirrusSearchWeightedTags" => [
				'build' => false,
			],
		] );
		$configBuilder = $this->createNoOpMock( AnalysisConfigBuilder::class );
		$analysisConfig = [];
		$handler->onCirrusSearchAnalysisConfig( $analysisConfig, $configBuilder );
		$this->assertSame( [], $analysisConfig );
	}

	public function testOnCirrusSearchAddQueryFeatures() {
		$config = new HashSearchConfig( [
			"CirrusSearchWeightedTags" => [
				'use' => false,
			],
		] );
		$handler = new WeightedTagsHooks( $config );
		$extraFeatures = [];
		$handler->onCirrusSearchAddQueryFeatures( $config, $extraFeatures );
		$this->assertSame( [], $extraFeatures );

		$config = new HashSearchConfig( [
			"CirrusSearchWeightedTags" => [
				'use' => true,
			],
		] );
		$handler = new WeightedTagsHooks( $config );
		$handler->onCirrusSearchAddQueryFeatures( $config, $extraFeatures );
		$this->assertKeywordsRegistered( $extraFeatures );
	}

	/**
	 * @param array $extraFeatures
	 * @return void
	 */
	private function assertKeywordsRegistered( array $extraFeatures ): void {
		$this->assertNotEmpty( $extraFeatures );
		$this->assertInstanceOf( ArticlePredictionKeyword::class, $extraFeatures[0] );
		$this->assertInstanceOf( HasRecommendationFeature::class, $extraFeatures[1] );
	}

	/**
	 * @param \SearchIndexField $fields
	 * @param \SearchEngine $searchEngine
	 * @return void
	 */
	private function assertSearchIndexFieldsRegistered( array $fields, \SearchEngine $searchEngine ): void {
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_NAME, $fields );
		$field = $fields[WeightedTagsHooks::FIELD_NAME];
		$this->assertInstanceOf( WeightedTags::class, $field );
		$mapping = $field->getMapping( $searchEngine );
		$this->assertSame( 'text', $mapping['type'] );
		$this->assertSame( WeightedTagsHooks::FIELD_SEARCH_ANALYZER, $mapping['search_analyzer'] );
		$this->assertSame( WeightedTagsHooks::FIELD_INDEX_ANALYZER, $mapping['analyzer'] );
		$this->assertSame( WeightedTagsHooks::FIELD_SIMILARITY, $mapping['similarity'] );
	}

	/**
	 * @param array $sim
	 * @param int $maxScore
	 * @return void
	 */
	private function assertSimilarityRegistered( array $sim, int $maxScore ): void {
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_SIMILARITY, $sim );
		$this->assertStringContainsString( $maxScore,
			$sim[WeightedTagsHooks::FIELD_SIMILARITY]['script']['source'] );
	}

	/**
	 * @param array $analysisConfig
	 * @param int $maxScore
	 * @return void
	 */
	private function assertAnalysisConfigRegistered( array $analysisConfig, int $maxScore ): void {
		$this->assertArrayHasKey( 'analyzer', $analysisConfig );
		$this->assertArrayHasKey( 'filter', $analysisConfig );
		$analyzers = $analysisConfig['analyzer'];
		$filters = $analysisConfig['filter'];
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_INDEX_ANALYZER, $analyzers );
		$this->assertArrayHasKey( 'weighted_tags_term_freq', $filters );
		$this->assertSame( $maxScore, $filters['weighted_tags_term_freq']['max_tf'] );
	}
}
