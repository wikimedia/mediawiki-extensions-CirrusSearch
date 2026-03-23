<?php

namespace CirrusSearch;

use CirrusSearch\SecondTry\SecondTrySearchFactory;
use MediaWiki\Language\Language;

/**
 * @covers \CirrusSearch\NamespaceMatcher
 */
class NamespaceMatcherTest extends CirrusTestCase {
	public function provideCustomProfile(): \Generator {
		yield 'no profiles' => [
			[
				'CirrusSearchNamespaceResolutionMethod' => 'naive',
				'CirrusSearchNamespaceMatcherProfiles' => [],
				'CirrusSearchSecondTryProfiles' => [],
			],
			'laetitia',
			null
		];
		yield 'hebrew wrong keyboard' => [
			[
				'CirrusSearchNamespaceResolutionMethod' => 'utr30_with_hebrew_wrong_keyboard',
			],
			'uhehpshv', // wrong keyboard for ויקיפדיה
			4
		];
		yield 'hebrew wrong keyboard reverse' => [
			[
				'CirrusSearchNamespaceResolutionMethod' => 'utr30_with_hebrew_wrong_keyboard',
			],
			'ויקיפדיה', // correct Hebrew
			4
		];
	}

	/**
	 * @dataProvider provideCustomProfile
	 */
	public function testCustomProfile( array $config, string $ns, ?int $expectedNs ): void {
		$searchConfig = $this->newHashSearchConfig( $config );
		$language = $this->createMock( Language::class );
		$language->expects( $this->atMost( 1 ) )
			->method( 'getNamespaceIds' )
			->willReturn( [ 'foo' => 1, 'Lætitia' => 2, 'ויקיפדיה' => 4 ] );
		$language->expects( $this->atMost( 1 ) )
			->method( 'lc' )
			->willReturnCallback( static fn ( string $text ) => mb_strtolower( $text ) );
		$namespaceMatcher = NamespaceMatcher::create( $language, new SecondTrySearchFactory(), $searchConfig );
		$this->assertEquals( $expectedNs, $namespaceMatcher->identifyNamespace( $ns ) );
	}
}
