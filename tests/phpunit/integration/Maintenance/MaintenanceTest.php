<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Maintenance\Maintenance;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CirrusSearch
 */
class MaintenanceTest extends MaintenanceBaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( CirrusConfigNames::WeightedTags, [
			'build' => false,
			'use' => false
		] );
	}

	/** @inheritDoc */
	protected function getMaintenanceClass() {
		return Maintenance::class;
	}

	/** @inheritDoc */
	protected function createMaintenance() {
		$obj = new class extends Maintenance {
			// Instantiate the abstract method for testing
			public function execute() {
			}

			// Allow test case to set the search config after construction
			public function setSearchConfig( array $config ) {
				$this->searchConfig = new HashSearchConfig( $config );
			}
		};
		return TestingAccessWrapper::newFromObject( $obj );
	}

	public static function decideClusterProvider() {
		// Defaults from extension.json
		$unconfigured = [
			'CirrusSearchServers' => [ 'localhost:9200' ],
		];
		// Multiple managed clusters and an unmanaged discovery cluster
		$complexConfig = [
			CirrusConfigNames::DefaultCluster => 'discovery',
			CirrusConfigNames::ReplicaGroup => 'default',
			CirrusConfigNames::Clusters => [
				'discovery' => [ 'search.discovery:9200' ],
				'dc1' => [ 'search.dc1:9200' ],
				'dc2' => [ 'search.dc2:9200' ],
			],
			CirrusConfigNames::ManagedClusters => [ 'dc1', 'dc2' ],
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
					CirrusConfigNames::DefaultCluster => 'dc1',
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
		$this->maintenance->setSearchConfig( $config );
		$this->maintenance->loadWithArgv( [ '--cluster', $requested ] );
		if ( $expected === null ) {
			$this->expectCallToFatalError();
			$this->expectOutputRegex(
				"/not configured for (cluster|maintenance) operations/i"
			);
		}
		$conn = $this->maintenance->getConnection();
		if ( $expected !== null ) {
			$this->assertEquals( $expected, $conn->getClusterName() );
		}
	}
}
