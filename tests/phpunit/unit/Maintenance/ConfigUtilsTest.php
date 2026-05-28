<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Test\NoopPrinter;
use Elastica\Response;

class ConfigUtilsTest extends CirrusTestCase {
	public static function scanAvailablePluginsProvider() {
		return [
			'no plugins reported' => [
				[], [], []
			],
			'plugins included but empty' => [
				[], [], [ 'plugins' => [] ],
			],
			'with some custom plugins' => [
				[ 'test-plugin' ],
				[],
				[
					'plugins' => [
						[ 'name' => 'test-plugin' ],
					]
				]
			],
			'filters plugins if requested' => [
				[],
				[ 'test-plugin' ],
				[
					'plugins' => [
						[ 'name' => 'test-plugin' ],
					]
				]
			],
		];
	}

	/**
	 * @covers \CirrusSearch\Maintenance\ConfigUtils::scanAvailablePlugins
	 * @covers \CirrusSearch\Maintenance\ConfigUtils::scanModulesOrPlugins
	 * @dataProvider scanAvailablePluginsProvider
	 */
	public function testScanAvailablePlugins( array $expectedPlugins, array $bannedPlugins, array $nodeResponse ) {
		$client = $this->createMock( \Elastica\Client::class );
		$client->method( 'request' )
			->with( '_nodes' )
			->willReturn( new \Elastica\Response( [
				'nodes' => [
					'somenode' => $nodeResponse
				]
			], 200 ) );

		$utils = new ConfigUtils( $client, new NoopPrinter() );
		$availablePlugins = $utils->scanAvailablePlugins( $bannedPlugins );
		$this->assertStatusGood( $availablePlugins );
		$this->assertEquals( $expectedPlugins, $availablePlugins->getValue() );
	}

	public static function provideTestCheckElasticVersion(): \Generator {
		yield 'opensearch 1.3.20' => [
			true,
			[
				"version" => [
					"distribution" => "opensearch",
					"number" => "1.3.20"
				]
			]
		];
		yield 'opensearch 2.19.5' => [
			true,
			[
				"version" => [
					"distribution" => "opensearch",
					"number" => "2.19.5"
				]
			]
		];
		yield 'opensearch 3.6.0' => [
			false,
			[
				"version" => [
					"distribution" => "opensearch",
					"number" => "3.6.0"
				]
			]
		];
		yield 'elasticsearch 7.10.2' => [
			true,
			[
				"version" => [
					"number" => "7.10.2"
				]
			]
		];
		yield 'elasticsearch 6.8.23' => [
			false,
			[
				"version" => [
					"number" => "6.8.23"
				]
			]
		];
	}

	/**
	 * @param bool $supported
	 * @param array $response
	 * @return void
	 * @covers \CirrusSearch\Maintenance\ConfigUtils::checkElasticsearchVersion
	 * @dataProvider provideTestCheckElasticVersion
	 */
	public function testCheckElasticVersion( bool $supported, array $response ): void {
		$client = $this->createMock( \Elastica\Client::class );
		$client->expects( $this->once() )->method( 'request' )->with( '' )->willReturn( new Response( $response, 200 ) );
		$configUtil = new ConfigUtils( $client, new NoopPrinter() );
		$status = $configUtil->checkElasticsearchVersion();
		$this->assertEquals( $supported, $status->isGood() );
	}
}
