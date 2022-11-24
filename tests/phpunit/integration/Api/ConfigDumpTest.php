<?php

use CirrusSearch\Api\ConfigDump;

/**
 * @covers \CirrusSearch\Api\ProfilesDump
 */
class ConfigDumpTest extends \CirrusSearch\CirrusIntegrationTestCase {
	/**
	 * @throws MWException
	 */
	public function testHappyPath() {
		$request = new FauxRequest( [] );
		$context = new RequestContext();
		$context->setRequest( $request );
		$main = new ApiMain( $context );
		$this->overrideConfigValues( [
			"CirrusSearchDefaultCluster" => "my_replica",
			"CirrusSearchClusters" => [
				"my_replica-cluster_group1" => [
					"group" => "cluster_group1",
					"replica" => "my_replica",
				],
				"my_replica-cluster_group2" => [
					"group" => "cluster_group2",
					"replica" => "my_replica",
				],
			],
			"CirrusSearchReplicaGroup" => [
				"type" => "roundrobin",
				"groups" => [
					"cluster_group1",
					"cluster_group2",
				],
			],
		] );

		$api = new ConfigDump( $main, 'name', '' );
		$api->execute();

		$result = $api->getResult();
		$this->assertNull( $result->getResultData( [ 'wgSecretKey' ] ),
			"MW Core config should not be exported" );
		$this->assertNotNull( $result->getResultData( [ 'CirrusSearchConnectionAttempts' ] ),
			"CirrusSearch config should be exported" );

		$namespaceMap = $result->getResultData( [ 'CirrusSearchConcreteNamespaceMap' ] );
		$this->assertNotNull( $namespaceMap, "Must include namespace map" );
		// Arbitrary selection of namespaces that should exist.
		foreach ( [ NS_MAIN, NS_TALK, NS_HELP ] as $ns ) {
			$this->assertArrayHasKey( $ns, $namespaceMap );
		}

		$clusterGroup = $result->getResultData( [ 'CirrusSearchConcreteReplicaGroup' ] );
		$this->assertNotNull( $clusterGroup );
		$this->assertContains( $clusterGroup, [ 'cluster_group1', 'cluster_group2' ] );
	}
}
