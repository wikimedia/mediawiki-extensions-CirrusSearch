<?php

namespace CirrusSearch\SecondTry;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Language\ILanguageConverter;

/**
 * @covers \CirrusSearch\SecondTry\SecondTryLanguageConverter
 */
class SecondTryLanguageConverterTest extends CirrusTestCase {
	public function providesTest(): \Generator {
		yield 'simple' => [ 'foo', [ 'bar', 'baz' ], [ 'bar', 'baz' ], [] ];
		yield 'simple limited' => [ 'foo', [ 'bar', 'baz' ], [ 'bar' ], [ 'top_k' => 1 ], ];
		yield 'default limit' => [ 'foo', [ 'bar', 'baz', 'qux', 'quux' ], [ 'bar', 'baz', 'qux' ], [], ];
		yield 'empty' => [ 'foo', [], [], [ 'top_k' => 1 ], ];
	}

	/**
	 * @dataProvider providesTest
	 */
	public function test( $input, array $outputs, array $expected_outputs, array $config ) {
		$languageConverter = $this->createMock( ILanguageConverter::class );
		$languageConverter->method( 'autoConvertToAllVariants' )->willReturnCallback(
			function ( string $param ) use ( $input, $outputs ): array {
				$this->assertEquals( $input, $param );
				return $outputs;
			}
		);
		$secondTryLanguageConverter = SecondTryLanguageConverter::build( $languageConverter, $config );
		$this->assertEquals( $expected_outputs, $secondTryLanguageConverter->candidates( $input ) );
	}
}
