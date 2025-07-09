<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\HashSearchConfig;
use MediaWiki\Maintenance\MaintenanceFatalError;
use MediaWikiIntegrationTestCase;

/**
 * @group CirrusSearch
 */
class MaintenanceTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'CirrusSearchWeightedTags', [
			'build' => false,
			'use' => false
		] );
	}

	public static function decideClusterProvider() {
		// Defaults from extension.json
		$unconfigured = [
			'CirrusSearchServers' => [ 'localhost:9200' ],
		];
		// Multiple managed clusters and an unmanaged discovery cluster
		$complexConfig = [
			'CirrusSearchDefaultCluster' => 'discovery',
			'CirrusSearchReplicaGroup' => 'default',
			'CirrusSearchClusters' => [
				'discovery' => [ 'search.discovery:9200' ],
				'dc1' => [ 'search.dc1:9200' ],
				'dc2' => [ 'search.dc2:9200' ],
			],
			'CirrusSearchManagedClusters' => [ 'dc1', 'dc2' ],
		];

		return [
			'empty config default cluster' => [
				'default', // expected
				null, // requested
				$unconfigured // config
			],
			'empty config requested cluster' => [
				null, // simple config doesn't allow named cluster
				'default',
				$unconfigured
			],
			'empty config request unknown cluster' => [
				null, // simple config doesn't allow named cluster
				'unknown',
				$unconfigured
			],
			'complex server unmanaged default cluster' => [
				null, // fails as the default cluster is not managed
				null,
				$complexConfig
			],
			'complex server managed default cluster' => [
				'dc1', // works as the default cluster is managed
				null,
				[
					'CirrusSearchDefaultCluster' => 'dc1',
				] + $complexConfig,
			],
			'complex server request unmanaged cluster' => [
				null, // requesting unmanaged fails
				'discovery',
				$complexConfig
			],
			'complex server request unknown cluster' => [
				null, // requesting unknown fails
				'unconfigured',
				$complexConfig
			],
			'complex server request managed cluster' => [
				'dc1',
				'dc1',
				$complexConfig
			],
		];
	}

	/**
	 * @dataProvider decideClusterProvider
	 * @covers \CirrusSearch\Maintenance\Maintenance::decideCluster
	 */
	public function testDecideCluster( $expected, $requested, array $config ) {
		$maint = new class ( new HashSearchConfig( $config ) ) extends Maintenance {
			public function execute() {
			}
		};
		$maint->loadWithArgv( [ '--cluster', $requested ] );
		if ( $expected === null ) {
			$this->expectException( MaintenanceFatalError::class );
			$this->expectOutputRegex(
				"/not configured for (cluster|maintenance) operations/i"
			);
		}
		$conn = $maint->getConnection();
		if ( $expected !== null ) {
			$this->assertEquals( $expected, $conn->getClusterName() );
		}
	}
}
