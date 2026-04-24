<?php

namespace CirrusSearch;

use CirrusSearch\SecondTry\SecondTrySearchFactory;
use MediaWiki\Language\Language;

/**
 * @covers \CirrusSearch\PrefixSearchExtractNamespace
 */
class PrefixSearchExtractNamespaceTest extends CirrusTestCase {
	public function testFactoryWithCirrusDisabled(): void {
		$config = new \HashConfig( [ 'SearchType' => 'unrelated' ] );
		$language = $this->createMock( Language::class );
		$namespaceMatcher = NamespaceMatcher::create( $language, new SecondTrySearchFactory( null ), $this->newHashSearchConfig( [] ) );
		$hookHandler = PrefixSearchExtractNamespace::create( $config, $namespaceMatcher );
		$search = 'foo';
		$ns = [ 0, 1 ];
		$this->assertFalse( $hookHandler->onPrefixSearchExtractNamespace( $ns, $search ) );
		$this->assertArrayEquals( [ 0, 1 ], $ns );
		$this->assertEquals( 'foo', $search );
	}

	public static function provideTestExtraction(): \Generator {
		yield 'simple with utr30' => [ 'utr30', 'foô:my search', [ 2, 3 ], 'my search', [ 1 ] ];
		yield 'simple with naive' => [ 'naive', 'foô:my search', [ 2, 3 ], 'my search', [ 1 ] ];
		yield 'extracted with utr30' => [ 'utr30', 'laetitia:my search', [ 2, 3 ], 'my search', [ 4 ] ];
		yield 'not extracted with naive' => [ 'naive', 'laetitia:my search', [ 2, 3 ], 'laetitia:my search', [ 2, 3 ] ];
		yield 'nothing extracted with utr30' => [ 'utr30', 'my search', [ 2, 3 ], 'my search', [ 2, 3 ] ];
		yield 'nothing extracted with naive' => [ 'naive', 'my search', [ 2, 3 ], 'my search', [ 2, 3 ] ];
		yield 'not a ns with utr30' => [ 'utr30', 'bar:my search', [ 2, 3 ], 'bar:my search', [ 2, 3 ] ];
		yield 'not a ns with naive' => [ 'naive', 'bar:my search', [ 2, 3 ], 'bar:my search', [ 2, 3 ] ];
	}

	/**
	 * @dataProvider provideTestExtraction
	 */
	public function testExtraction(
		string $method,
		string $search,
		array $namespaces,
		string $expectedSearch,
		array $expectedNamespace
	): void {
		$config = new \HashConfig( [ 'SearchType' => 'CirrusSearch' ] );
		$searchConfig = $this->newHashSearchConfig( [ 'CirrusSearchNamespaceResolutionMethod' => $method ] );
		$language = $this->createMock( Language::class );
		$language->expects( $this->atMost( 1 ) )
			->method( 'getNamespaceIds' )
			->willReturn( [ 'foo' => 1, 'Lætitia' => 4 ] );
		$language->expects( $this->atMost( 1 ) )
			->method( 'lc' )
			->willReturnCallback( static fn ( string $text ) => mb_strtolower( $text ) );

		$namespaceMatcher = NamespaceMatcher::create( $language, new SecondTrySearchFactory( null ), $searchConfig );
		$hookHandler = PrefixSearchExtractNamespace::create( $config, $namespaceMatcher );
		$hookHandler->onPrefixSearchExtractNamespace( $namespaces, $search );
		$this->assertArrayEquals( $expectedNamespace, $namespaces );
		$this->assertEquals( $expectedSearch, $search );
	}
}
