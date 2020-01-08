<?php

namespace CirrusSearch\Wikimedia;

/**
 * @covers \CirrusSearch\Wikimedia\ORESDraftTopicsHooks
 */
class ORESDraftTopicsHooksTest extends \MediaWikiUnitTestCase {
	public function testConfigureOresDraftTopicsSimilarity() {
		$sim = [];
		$maxScore = 17389;
		$config = new \HashConfig( [
				ORESDraftTopicsHooks::WMF_EXTRA_FEATURES => [
					ORESDraftTopicsHooks::CONFIG_OPTIONS => [
						ORESDraftTopicsHooks::BUILD_OPTION => true,
						ORESDraftTopicsHooks::MAX_SCORE_OPTION => $maxScore,
					]
				]
		] );
		ORESDraftTopicsHooks::configureOresDraftTopicsSimilarity( $sim, $config );
		$this->assertArrayHasKey( ORESDraftTopicsHooks::FIELD_SIMILARITY, $sim );
		$this->assertStringContainsString( $maxScore,
			$sim[ORESDraftTopicsHooks::FIELD_SIMILARITY]['script']['source'] );
	}

	public function testConfigureOresDraftTopicsSimilarityDisabled() {
		$config = new \HashConfig( [
			ORESDraftTopicsHooks::WMF_EXTRA_FEATURES => [
				ORESDraftTopicsHooks::CONFIG_OPTIONS => [
					ORESDraftTopicsHooks::BUILD_OPTION => false,
				]
			]
		] );
		$sim = [];
		ORESDraftTopicsHooks::configureOresDraftTopicsSimilarity( $sim, $config );
		$this->assertSame( [], $sim );
	}

	public function testConfigureOresDraftTopicsFieldMapping() {
		$config = new \HashConfig( [
			ORESDraftTopicsHooks::WMF_EXTRA_FEATURES => [
				ORESDraftTopicsHooks::CONFIG_OPTIONS => [
					ORESDraftTopicsHooks::BUILD_OPTION => true,
				]
			]
		] );
		$searchEngine = $this->createMock( \SearchEngine::class );
		/**
		 * @var \SearchIndexField $fields
		 */
		$fields = [];
		ORESDraftTopicsHooks::configureOresDraftTopicsFieldMapping( $fields, $config );
		$this->assertArrayHasKey( ORESDraftTopicsHooks::FIELD_NAME, $fields );
		$field = $fields[ORESDraftTopicsHooks::FIELD_NAME];
		$this->assertInstanceOf( ORESDraftTopicsField::class, $field );
		$mapping = $field->getMapping( $searchEngine );
		$this->assertSame( 'text', $mapping['type'] );
		$this->assertSame( ORESDraftTopicsHooks::FIELD_SEARCH_ANALYZER, $mapping['search_analyzer'] );
		$this->assertSame( ORESDraftTopicsHooks::FIELD_INDEX_ANALYZER, $mapping['analyzer'] );
		$this->assertSame( ORESDraftTopicsHooks::FIELD_SIMILARITY, $mapping['similarity'] );
	}

	public function testConfigureOresDraftTopicsFieldMappingDisabled() {
		$config = new \HashConfig( [
			ORESDraftTopicsHooks::WMF_EXTRA_FEATURES => [
				ORESDraftTopicsHooks::CONFIG_OPTIONS => [
					ORESDraftTopicsHooks::BUILD_OPTION => false,
				]
			]
		] );
		$fields = [];
		ORESDraftTopicsHooks::configureOresDraftTopicsFieldMapping( $fields, $config );
		$this->assertSame( [], $fields );
	}

	public function testConfigureOresDraftTopicsFieldAnalysis() {
		$maxScore = 41755;
		$config = new \HashConfig( [
			ORESDraftTopicsHooks::WMF_EXTRA_FEATURES => [
				ORESDraftTopicsHooks::CONFIG_OPTIONS => [
					ORESDraftTopicsHooks::BUILD_OPTION => true,
					ORESDraftTopicsHooks::MAX_SCORE_OPTION => $maxScore,
				]
			]
		] );
		$analysisConfig = [];
		ORESDraftTopicsHooks::configureOresDraftTopicsFieldAnalysis( $analysisConfig, $config );
		$this->assertArrayHasKey( 'analyzer', $analysisConfig );
		$this->assertArrayHasKey( 'filter', $analysisConfig );
		$analyzers = $analysisConfig['analyzer'];
		$filters = $analysisConfig['filter'];
		$this->assertArrayHasKey( ORESDraftTopicsHooks::FIELD_INDEX_ANALYZER, $analyzers );
		$this->assertArrayHasKey( 'ores_drafttopics_term_freq', $filters );
		$this->assertSame( $maxScore, $filters['ores_drafttopics_term_freq']['max_tf'] );
	}

	public function testConfigureOresDraftTopicsFieldAnalysisDisabled() {
		$config = new \HashConfig( [
			ORESDraftTopicsHooks::WMF_EXTRA_FEATURES => [
				ORESDraftTopicsHooks::CONFIG_OPTIONS => [
					ORESDraftTopicsHooks::BUILD_OPTION => false,
				]
			]
		] );
		$analysisConfig = [];
		ORESDraftTopicsHooks::configureOresDraftTopicsFieldAnalysis( $analysisConfig, $config );
		$this->assertSame( [], $analysisConfig );
	}
}
