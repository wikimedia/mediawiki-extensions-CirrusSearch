<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\HashSearchConfig;

class ReindexerTest extends \MediaWikiTestCase {

	public function provideDetectRemoteSourceParams() {
		return [
			'simple configuration' => [
				// Expected remote info
				[ 'host' => 'http://search.svc.eqiad.wmnet:9200/' ],
				// wgCirrusSearchClusters configuration
				[
					'eqiad' => [ 'search.svc.eqiad.wmnet' ],
					'codfw' => [ 'search.svc.codfw.wmnet' ],
				]
			],
			'no remote info if both are same' => [
				null,
				[
					'eqiad' => [ 'search.svc.eqiad.wmnet' ],
					'codfw' => [ 'search.svc.codfw.wmnet' ],
				],
				'eqiad',
				'eqiad',
			],
			'handles advanced cluster definitions' => [
				[ 'host' => 'https://search.svc.eqiad.wmnet:9243/' ],
				[
					'eqiad' => [
						[
							'transport' => 'CirrusSearch\\Elastica\\PooledHttps',
							'port' => '9243',
							'host' => 'search.svc.eqiad.wmnet',
						],
					],
					'codfw' => [ 'search.svc.codfw.wmnet' ],
				],
			],
			'uses http when http transport is selected' => [
				[ 'host' => 'http://search.svc.eqiad.wmnet:9200/' ],
				[
					'eqiad' => [
						[
							'transport' => 'Http',
							'port' => '9200',
							'host' => 'search.svc.eqiad.wmnet',
						],
					],
					'codfw' => [ 'search.svc.codfw.wmnet' ],
				]
			],
			'uses http when pooled http transport is selected' => [
				[ 'host' => 'http://search.svc.eqiad.wmnet:9200/' ],
				[
					'eqiad' => [
						[
							'transport' => 'CirrusSearch\\Elastica\\PooledHttp',
							'port' => 9200,
							'host' => 'search.svc.eqiad.wmnet',
						],
					],
					'codfw' => [ 'search.svc.codfw.wmnet' ],
				]
			],
		];
	}

	/**
	 * @dataProvider provideDetectRemoteSourceParams
	 */
	public function testDetectRemoteSourceParams( $expected, $clustersConfig, $sourceCluster = 'eqiad', $destCluster = 'codfw' ) {
		$config = new HashSearchConfig( [
			'CirrusSearchDefaultCluster' => 'eqiad',
			'CirrusSearchClusters' => $clustersConfig
		] );
		$source = new Connection( $config, $sourceCluster );
		$dest = new Connection( $config, $destCluster );
		$this->assertEquals( $expected, Reindexer::makeRemoteReindexInfo( $source, $dest ) );
	}
}
