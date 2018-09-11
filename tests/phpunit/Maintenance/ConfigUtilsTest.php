<?php

namespace CirrusSearch\Maintenance;

class NoopPrinter implements Printer {
	public function output( $message, $channel = null ) {
	}

	public function outputIndented( $message ) {
	}

	public function error( $err, $die = 0 ) {
		throw new \RuntimeException();
	}
}

class ConfigUtilsTest extends \PHPUnit\Framework\TestCase {
	public function scanAvailablePluginsProvider() {
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
		$client = $this->getMockBuilder( \Elastica\Client::class )
			->disableOriginalConstructor()
			->getMock();
		$client->expects( $this->any() )
			->method( 'request' )
			->with( '_nodes' )
			->will( $this->returnValue( new \Elastica\Response( [
				'nodes' => [
					'somenode' => $nodeResponse
				]
			] ) ) );

		$utils = new ConfigUtils( $client, new NoopPrinter() );
		$availablePlugins = $utils->scanAvailablePlugins( $bannedPlugins );
		$this->assertEquals( $expectedPlugins, $availablePlugins );
	}
}
