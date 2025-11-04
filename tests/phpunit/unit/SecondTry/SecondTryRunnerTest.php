<?php

namespace CirrusSearch\SecondTry;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Profile\SearchProfileService;
use MediaWiki\Language\ILanguageConverter;
use MediaWiki\Language\LanguageConverterFactory;
use Wikimedia\Assert\InvariantException;

/**
 * @covers \CirrusSearch\SecondTry\SecondTryRunner
 * @covers \CirrusSearch\SecondTry\SecondTryRunnerFactory
 */
class SecondTryRunnerTest extends CirrusTestCase {
	public function test() {
		$strategies = [
			"strategy1" => new class() implements SecondTrySearch {
				public function candidates( string $searchQuery ): array {
					return [ "strat1_cand1", "strat1_cand2" ];
				}
			},
			"strategy2" => new class() implements SecondTrySearch {
				public function candidates( string $searchQuery ): array {
					return [];
				}
			},
			"strategy3" => new class() implements SecondTrySearch {
				public function candidates( string $searchQuery ): array {
					return [ "strat3_cand1" ];
				}
			},
		];
		$weights = [
			"strategy1" => 1.0,
			"strategy2" => 0.7,
			"strategy3" => 0.3,
		];
		$runner = new SecondTryRunner( $strategies, $weights );
		$candidates = $runner->candidates( "foo" );
		$this->assertEquals( [
				"strategy1" => [ "strat1_cand1", "strat1_cand2" ],
				"strategy3" => [ "strat3_cand1" ],
			], $candidates );
		$this->assertSame( 1.0, $runner->weight( "strategy1" ) );
		$this->assertEquals( 0.7, $runner->weight( "strategy2" ) );
		$this->assertEquals( 0.3, $runner->weight( "strategy3" ) );
	}

	public function testBadConstructor() {
		$this->expectException( InvariantException::class );
		new SecondTryRunner( [], [ "bar" => 1.0 ] );
	}

	public function testFactory() {
		$languageConverter = $this->createMock( ILanguageConverter::class );
		// need at least two return values from langConv to test top_k == 1
		$languageConverter->method( 'autoConvertToAllVariants' )->willReturn( [ 'conv', 'conv2' ] );
		$languageConverterFactory = $this->createMock( LanguageConverterFactory::class );
		$languageConverterFactory->method( 'getLanguageConverter' )
			->willReturn( $languageConverter );
		$config = $this->newHashSearchConfig( [
			'CirrusSearchCompletionUseSecondTryProfile' => 'my_profile',
			'CirrusSearchSecondTryProfiles' => [
				'my_profile' => [
					'strategies' => [
						'russian_keyboard' => 1.0,
						'hebrew_keyboard' => [
							'weight' => 0.9,
						],
						'language_converter' => [
							'weight' => 0.8,
							'settings' => [
								'top_k' => 1,
							],
						],
					]
				],
			],
		] );
		$factory = new SecondTryRunnerFactory( new SecondTrySearchFactory( $languageConverterFactory ), $config );
		$runner = $factory->create( SearchProfileService::CONTEXT_COMPLETION );
		$candidates = $runner->candidates( 'foo' );
		$this->assertEquals( [
			'russian_keyboard' => [ 'ащщ' ],
			'hebrew_keyboard' => [ 'כםם' ],
			'language_converter' => [ 'conv' ]
		], $candidates );
		$actual_weights = array_map( static fn ( $s ) => $runner->weight( $s ), array_keys( $candidates ) );
		$this->assertEquals( [ 1.0, 0.9, 0.8 ], $actual_weights );
	}

	public function testOneWayDwimProfile() {
		$config = $this->newHashSearchConfig( [
			'CirrusSearchCompletionUseSecondTryProfile' => 'my_profile',
			'CirrusSearchSecondTryProfiles' => [
				'my_profile' => [
					'strategies' => [
						'russian_keyboard' => [
							'weight' => 1.0,
							'settings' => [ 'dir' => 'l2c' ]
						],
						'hebrew_keyboard' => [
							'weight' => 0.9,
							'settings' => [ 'dir' => 'h2l' ]
						],
					]
				],
			],
		] );
		$factory = new SecondTryRunnerFactory( new SecondTrySearchFactory(), $config );
		$runner = $factory->create( SearchProfileService::CONTEXT_COMPLETION );
		$candidates = $runner->candidates( 'foo ащщ כםם' );
		$this->assertEquals( [
			'russian_keyboard' => [ 'ащщ ащщ כםם' ],
			'hebrew_keyboard' => [ 'foo ащщ foo' ],
		], $candidates );
		$actual_weights = array_map( static fn ( $s ) => $runner->weight( $s ), array_keys( $candidates ) );
		$this->assertEquals( [ 1.0, 0.9 ], $actual_weights );
	}
}
