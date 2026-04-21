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
		yield 'custom profiles for hebrew' => [
			[
				'CirrusSearchNamespaceResolutionMethod' => 'utr30_with_hebrew_wrong_keyboard',
				'CirrusSearchNamespaceMatcherProfiles' => [
					'utr30_with_hebrew_wrong_keyboard' => [
						'index_second_try_profile' => 'icu_folding_utr30',
						'search_second_try_profile' => 'utr30_and_hebrew_wrong_keyboard',
					]
				],
				'CirrusSearchSecondTryProfiles' => [
					'utr30_and_hebrew_wrong_keyboard' => [
						'strategies' => [
							'icu_folding' => [
								'weight' => 1.0,
								'settings' => [
									'method' => 'utr30',
								]
							],
							'hebrew_keyboard' => [
								'weight' => 0.5,
								'settings' => [
									'dir' => 'l2h'
								]
							]
						]
					]
				],
			],
			'uhehpshv',
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
