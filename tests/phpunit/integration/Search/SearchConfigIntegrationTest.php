<?php

namespace CirrusSearch;

/**
 * @covers \CirrusSearch\SearchConfig
 */
class SearchConfigIntegrationTest extends CirrusIntegrationTestCase {
	public function testMWServiceIntegration() {
		$config = $this->getServiceContainer()->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		$this->assertInstanceOf( SearchConfig::class, $config );
	}

}
