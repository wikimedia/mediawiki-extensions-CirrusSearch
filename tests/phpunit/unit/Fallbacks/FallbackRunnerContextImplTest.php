<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Test\DummySearchResultSet;
use Elastica\ResultSet;

/**
 * @covers \CirrusSearch\Fallbacks\FallbackRunnerContextImpl
 */
class FallbackRunnerContextImplTest extends CirrusTestCase {

	public function testMethodResponse() {
		$context = new FallbackRunnerContextImpl( DummySearchResultSet::emptyResultSet(),
			$this->createNoOpMock( SearcherFactory::class ), $this->namespacePrefixParser(),
			$this->createCirrusSearchHookRunner() );
		$this->assertFalse( $context->hasMethodResponse() );
		$methodResponse = $this->createNoOpMock( ResultSet::class );
		$context->setSuggestResponse( $methodResponse );
		$this->assertTrue( $context->hasMethodResponse() );
		$this->assertSame( $methodResponse, $context->getMethodResponse() );
		$context->resetSuggestResponse();
		$this->assertFalse( $context->hasMethodResponse() );
	}
}
