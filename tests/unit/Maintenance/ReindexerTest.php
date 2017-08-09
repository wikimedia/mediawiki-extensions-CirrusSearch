<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\HashSearchConfig;

class ReindexerTest extends \MediaWikiTestCase {

	public function provideDetectRemoteSourceParams() {
		return [
			'simple configuration' => [
				// Expected remote info
				[ 'host' => 'http://search.svc.foo.local:9200/' ],
				// wgCirrusSearchClusters configuration
				[
					'dc-foo' => [ 'search.svc.foo.local' ],
					'dc-bar' => [ 'search.svc.bar.local' ],
				]
			],
			'no remote info if both are same' => [
				null,
				[
					'dc-foo' => [ 'search.svc.foo.local' ],
					'dc-bar' => [ 'search.svc.bar.local' ],
				],
				'dc-foo',
				'dc-foo',
			],
			'handles advanced cluster definitions' => [
				[ 'host' => 'https://search.svc.foo.local:9243/' ],
				[
					'dc-foo' => [
						[
							'transport' => 'CirrusSearch\\Elastica\\PooledHttps',
							'port' => '9243',
							'host' => 'search.svc.foo.local',
						],
					],
					'dc-bar' => [ 'search.svc.bar.local' ],
				],
			],
			'uses http when http transport is selected' => [
				[ 'host' => 'http://search.svc.foo.local:9200/' ],
				[
					'dc-foo' => [
						[
							'transport' => 'Http',
							'port' => '9200',
							'host' => 'search.svc.foo.local',
						],
					],
					'dc-bar' => [ 'search.svc.bar.local' ],
				]
			],
			'uses http when pooled http transport is selected' => [
				[ 'host' => 'http://search.svc.foo.local:9200/' ],
				[
					'dc-foo' => [
						[
							'transport' => 'CirrusSearch\\Elastica\\PooledHttp',
							'port' => 9200,
							'host' => 'search.svc.foo.local',
						],
					],
					'dc-bar' => [ 'search.svc.bar.local' ],
				]
			],
		];
	}

	/**
	 * @dataProvider provideDetectRemoteSourceParams
	 */
	public function testDetectRemoteSourceParams( $expected, $clustersConfig, $sourceCluster = 'dc-foo', $destCluster = 'dc-bar' ) {
		$config = new HashSearchConfig( [
			'CirrusSearchDefaultCluster' => 'dc-foo',
			'CirrusSearchClusters' => $clustersConfig
		] );
		$source = new Connection( $config, $sourceCluster );
		$dest = new Connection( $config, $destCluster );
		$this->assertEquals( $expected, Reindexer::makeRemoteReindexInfo( $source, $dest ) );
	}
}
