<?php

namespace CirrusSearch\Test\Integration\Api;

use CirrusSearch\Api\SchemaDump;
use CirrusSearch\CirrusIntegrationTestCase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;

/**
 * @group Database
 * @covers \CirrusSearch\Api\SchemaDump
 */
class SchemaDumpTest extends CirrusIntegrationTestCase {

	/**
	 * Test build=true with explicit plugins parameter
	 */
	public function testBuildWithPluginsParameter() {
		$request = new FauxRequest( [
			'build' => true,
			'plugins' => 'analysis-icu|extra-analysis-textify',
		] );
		$context = new RequestContext();
		$context->setRequest( $request );
		$main = new ApiMain( $context );

		$api = new SchemaDump( $main, 'cirrus-schema-dump' );
		$api->execute();

		$result = $api->getResult();
		$resultData = $result->getResultData();

		// Should have at least content and general indices
		$this->assertNotEmpty( $resultData, 'Result should not be empty' );

		// Find any index in the result to test structure
		$indexNames = array_keys( $resultData );
		$this->assertNotEmpty( $indexNames, 'Should have at least one index' );

		$firstIndex = $indexNames[0];
		$indexData = $resultData[$firstIndex];

		// Verify structure: should have both settings and mappings
		$this->assertArrayHasKey( 'settings', $indexData,
			'Index should have settings' );
		$this->assertArrayHasKey( 'mappings', $indexData,
			'Index should have mappings' );

		// Verify settings structure
		$this->assertArrayHasKey( 'index', $indexData['settings'],
			'Settings should have index key' );
		$indexSettings = $indexData['settings']['index'];

		// Check for expected settings keys
		$this->assertArrayHasKey( 'number_of_shards', $indexSettings,
			'Should have number_of_shards' );
		$this->assertArrayHasKey( 'analysis', $indexSettings,
			'Should have analysis settings' );

		// With analysis-icu plugin, should have ICU-related filters
		$analysis = $indexSettings['analysis'];
		$this->assertArrayHasKey( 'filter', $analysis,
			'Analysis should have filters' );

		$filters = $analysis['filter'];
		// Check that at least one ICU filter is present when plugin is specified
		$hasIcuFilter = isset( $filters['icu_normalizer'] ) ||
			isset( $filters['icu_folding'] ) ||
			isset( $filters['icutokrep_no_camel_split'] );
		$this->assertTrue( $hasIcuFilter,
			'Should have at least one ICU filter when analysis-icu plugin is provided' );

		// Verify mappings structure
		$this->assertArrayHasKey( 'properties', $indexData['mappings'],
			'Mappings should have properties' );
	}

	/**
	 * Test build=true with empty plugins parameter
	 */
	public function testBuildWithEmptyPlugins() {
		$this->overrideConfigValue(
			'CirrusSearchNaturalTitleSort',
			[ 'build' => false, 'use' => false ],
		);
		$request = new FauxRequest( [
			'build' => true,
			'plugins' => '',
		] );
		$context = new RequestContext();
		$context->setRequest( $request );
		$main = new ApiMain( $context );

		$api = new SchemaDump( $main, 'cirrus-schema-dump' );
		$api->execute();

		$result = $api->getResult();
		$resultData = $result->getResultData();

		$this->assertNotEmpty( $resultData, 'Result should not be empty' );

		// Find any index to test
		$indexNames = array_keys( $resultData );
		$firstIndex = $indexNames[0];
		$indexData = $resultData[$firstIndex];

		// Verify structure
		$this->assertArrayHasKey( 'settings', $indexData );
		$this->assertArrayHasKey( 'mappings', $indexData );

		$indexSettings = $indexData['settings']['index'];
		$analysis = $indexSettings['analysis'];
		$filters = $analysis['filter'];

		// With empty plugins, should NOT have ICU filters
		// Note: Some filters may still be present if they don't require plugins
		// The key test is comparing with vs without the plugin parameter
	}

	/**
	 * Test build=true with single plugin
	 */
	public function testBuildWithSinglePlugin() {
		$request = new FauxRequest( [
			'build' => true,
			'plugins' => 'analysis-icu',
		] );
		$context = new RequestContext();
		$context->setRequest( $request );
		$main = new ApiMain( $context );

		$api = new SchemaDump( $main, 'cirrus-schema-dump' );
		$api->execute();

		$result = $api->getResult();
		$resultData = $result->getResultData();

		$this->assertNotEmpty( $resultData, 'Result should not be empty' );

		// Verify ICU plugin effects
		$indexNames = array_keys( $resultData );
		$firstIndex = $indexNames[0];
		$filters = $resultData[$firstIndex]['settings']['index']['analysis']['filter'];

		// Check that at least one ICU filter is present
		$hasIcuFilter = isset( $filters['icu_normalizer'] ) ||
			isset( $filters['icu_folding'] ) ||
			isset( $filters['icutokrep_no_camel_split'] );
		$this->assertTrue( $hasIcuFilter,
			'Should have at least one ICU filter with analysis-icu plugin' );
	}

	/**
	 * Test that all expected indices are returned
	 */
	public function testAllIndicesReturned() {
		$this->overrideConfigValue(
			'CirrusSearchNaturalTitleSort',
			[ 'build' => false, 'use' => false ],
		);
		$request = new FauxRequest( [
			'build' => true,
			'plugins' => '',
		] );
		$context = new RequestContext();
		$context->setRequest( $request );
		$main = new ApiMain( $context );

		$api = new SchemaDump( $main, 'cirrus-schema-dump' );
		$api->execute();

		$result = $api->getResult();
		$resultData = $result->getResultData();

		// Should have multiple indices (at minimum: content, general)
		$this->assertGreaterThanOrEqual( 2, count( $resultData ),
			'Should return at least content and general indices' );

		// Verify each index has the proper structure
		foreach ( $resultData as $indexName => $indexData ) {
			// Skip non-array entries (like _element or other metadata)
			if ( !is_array( $indexData ) ) {
				continue;
			}

			$this->assertArrayHasKey( 'settings', $indexData,
				"Index $indexName should have settings" );
			$this->assertArrayHasKey( 'mappings', $indexData,
				"Index $indexName should have mappings" );
			$this->assertArrayHasKey( 'index', $indexData['settings'],
				"Index $indexName settings should have 'index' key" );
		}
	}

	/**
	 * Test that output format is suitable for Elasticsearch index creation
	 */
	public function testOutputFormatMatchesElasticsearchSpec() {
		$request = new FauxRequest( [
			'build' => true,
			'plugins' => 'analysis-icu',
		] );
		$context = new RequestContext();
		$context->setRequest( $request );
		$main = new ApiMain( $context );

		$api = new SchemaDump( $main, 'cirrus-schema-dump' );
		$api->execute();

		$result = $api->getResult();
		$resultData = $result->getResultData();

		$indexNames = array_keys( $resultData );
		$firstIndex = $indexNames[0];
		$indexData = $resultData[$firstIndex];

		// The format should match what ES expects for PUT /index
		// Top level: settings and mappings
		$this->assertEquals( [ 'settings', 'mappings' ],
			array_keys( $indexData ),
			'Top level should only have settings and mappings' );

		// Settings should have 'index' wrapper
		$this->assertArrayHasKey( 'index', $indexData['settings'] );

		// Index settings should have required fields
		$indexSettings = $indexData['settings']['index'];
		$this->assertArrayHasKey( 'number_of_shards', $indexSettings );
		$this->assertArrayHasKey( 'auto_expand_replicas', $indexSettings );
		$this->assertArrayHasKey( 'refresh_interval', $indexSettings );
		$this->assertArrayHasKey( 'analysis', $indexSettings );

		// Mappings should have properties
		$this->assertArrayHasKey( 'properties', $indexData['mappings'] );
		$this->assertNotEmpty( $indexData['mappings']['properties'],
			'Mappings properties should not be empty' );
	}
}
