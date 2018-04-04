<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\SearchConfig;

/**
 * Providers helper for writing tests of classes extending from
 * SimpleKeywordFeature
 */
abstract class BaseSimpleKeywordFeatureTest extends CirrusTestCase {

	/**
	 * @return SearchContext
	 */
	protected function mockContext() {
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();
		$context->expects( $this->any() )->method( 'getConfig' )->willReturn( new SearchConfig() );
		$context->expects( $this->any() )->method( 'escaper' )->willReturn( new Escaper( 'en', true ) );

		return $context;
	}

	protected function mockContextExpectingAddFilter( array $expectedQuery = null ) {
		$context = $this->mockContext();
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

		return $context;
	}

	protected function assertWarnings( KeywordFeature $feature, $expected, $term ) {
		$warnings = [];
		$context = $this->mockContext();
		$context->expects( $this->any() )
			->method( 'addWarning' )
			->will( $this->returnCallback( function () use ( &$warnings ) {
				$warnings[] = array_filter( func_get_args() );
			} ) );
		$feature->apply( $context, $term );
		$this->assertEquals( $expected, $warnings );
	}
}
