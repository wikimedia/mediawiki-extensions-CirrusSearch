<?php

namespace CirrusSearch;

use CirrusSearch\Query\ArticlePredictionKeyword;
use CirrusSearch\Search\WeightedTags;
use CirrusSearch\Search\WeightedTagsHooks;
use MediaWiki\Config\HashConfig;

/**
 * @covers \CirrusSearch\Search\WeightedTagsHooks
 */
class WeightedTagsHooksTest extends \MediaWikiUnitTestCase {
	public function testConfigureWeightedTagsSimilarity() {
		$sim = [];
		$maxScore = 17389;
		$config = new HashConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'build' => true,
				'max_score' => $maxScore,
			]
		] );
		WeightedTagsHooks::configureWeightedTagsSimilarity( $sim, $config );
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_SIMILARITY, $sim );
		$this->assertStringContainsString( $maxScore,
			$sim[WeightedTagsHooks::FIELD_SIMILARITY]['script']['source'] );
	}

	public function testConfigureWeightedTagsSimilarityDisabled() {
		$config = new HashConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'build' => false,
			]
		] );
		$sim = [];
		WeightedTagsHooks::configureWeightedTagsSimilarity( $sim, $config );
		$this->assertSame( [], $sim );
	}

	public function testConfigureWeightedTagsFieldMapping() {
		$config = new HashConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'build' => true,
			]
		] );
		$searchEngine = $this->createNoOpMock( \SearchEngine::class );
		/**
		 * @var \SearchIndexField $fields
		 */
		$fields = [];
		WeightedTagsHooks::configureWeightedTagsFieldMapping( $fields, $config );
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
		$config = new HashConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'build' => false,
			]
		] );
		$fields = [];
		WeightedTagsHooks::configureWeightedTagsFieldMapping( $fields, $config );
		$this->assertSame( [], $fields );
	}

	public function testConfigureWeightedTagsFieldAnalysis() {
		$maxScore = 41755;
		$config = new HashConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'build' => true,
				'max_score' => $maxScore,
			]
		] );
		$analysisConfig = [];
		WeightedTagsHooks::configureWeightedTagsFieldAnalysis( $analysisConfig, $config );
		$this->assertArrayHasKey( 'analyzer', $analysisConfig );
		$this->assertArrayHasKey( 'filter', $analysisConfig );
		$analyzers = $analysisConfig['analyzer'];
		$filters = $analysisConfig['filter'];
		$this->assertArrayHasKey( WeightedTagsHooks::FIELD_INDEX_ANALYZER, $analyzers );
		$this->assertArrayHasKey( 'weighted_tags_term_freq', $filters );
		$this->assertSame( $maxScore, $filters['weighted_tags_term_freq']['max_tf'] );
	}

	public function testConfigureWeightedTagsFieldAnalysisDisabled() {
		$config = new HashConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'build' => false,
			]
		] );
		$analysisConfig = [];
		WeightedTagsHooks::configureWeightedTagsFieldAnalysis( $analysisConfig, $config );
		$this->assertSame( [], $analysisConfig );
	}

	public function testOnCirrusSearchAddQueryFeatures() {
		$config = new HashSearchConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'use' => false,
			],
		] );
		$extraFeatures = [];
		WeightedTagsHooks::onCirrusSearchAddQueryFeatures( $config, $extraFeatures );
		$this->assertSame( [], $extraFeatures );

		$config = new HashSearchConfig( [
			'CirrusSearchWMFExtraFeatures' => [],
			'CirrusSearchWeightedTags' => [
				'use' => true,
			],
		] );
		WeightedTagsHooks::onCirrusSearchAddQueryFeatures( $config, $extraFeatures );
		$this->assertNotEmpty( $extraFeatures );
		$this->assertInstanceOf( ArticlePredictionKeyword::class, $extraFeatures[0] );
	}
}
