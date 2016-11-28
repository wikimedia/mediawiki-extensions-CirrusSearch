<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\SearchConfig;

/**
 * Providers helper for writing tests of classes extending from
 * SimpleKeywordFeature
 */
abstract class BaseSimpleKeywordFeatureTest extends CirrusTestCase {
	protected function mockContextExpectingAddFilter( array $expectedQuery = null ) {
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();

		if ( $expectedQuery === null ) {
			$context->expects( $this->never() )
				->method( 'addFilter' );
		} else {
			$context->expects( $this->once() )
				->method( 'addFilter' )
				->with( $this->callback( function ( $query ) use ( $expectedQuery ) {
					$this->assertEquals( $expectedQuery, $query->toArray() );
					return true;
				} ) );
		}
		$context->expects( $this->any() )->method( 'getConfig' )->willReturn( new SearchConfig() );

		return $context;
	}
}
