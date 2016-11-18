<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Test\HashSearchConfig;
use CirrusSearch\CirrusTestCase;

/**
 * @group CirrusSearch
 * @covers CirrusSearch\Maintenance\AnalysisConfigBuilder
 */
class AnalysisConfigBuilderTest extends CirrusTestCase {
	/** @dataProvider provideASCIIFoldingFilters */
	public function testASCIIFoldingFix( array $input, array $expected ) {
		$config = new HashSearchConfig([]);
		$plugins = ['extra', 'analysis-icu'];
		$builder = new AnalysisConfigBuilder( 'en', $plugins, $config );
		$result = $builder->fixAsciiFolding( $input );

		$this->assertEquals( $expected['analyzer'], $result['analyzer'] );
		if ( isset( $expected['filter'] ) ) {
			$this->assertEquals( $expected['filter'], $result['filter'] );
		} else {
			$this->assertFalse( isset ( $result['filter'] ) );
		}
	}

	public static function provideASCIIFoldingFilters() {
		return [
			'only custom is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => ['asciifolding_preserve']
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'french',
							'filter' => ['asciifolding_preserve']
						]
					],
				],
			],
			'only asciifolding_preserve is updated' => [
				[
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => ['asciifolding']
						]
					],
				],
				[
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => ['asciifolding']
						],
					],
				],
			],
			'dedup filter is appended after asciifolding_preserve' => [
				[
					'filter' => [
						'asciifolding_preserve' => [
							'type' => 'asciifolding',
							'preserve_original' => true,
						],
					],
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => [
								'asciifolding_preserve',
								'french_stemmer',
							],
						],
					],
				],
				[
					'filter' => [
						'asciifolding_preserve' => [
							'type' => 'asciifolding',
							'preserve_original' => true,
						],
						'dedup_asciifolding' => [
							'type' => 'unique',
							'only_on_same_position' => true,
						],
					],
					'analyzer' => [
						'french' => [
							'type' => 'custom',
							'filter' => [
								'asciifolding_preserve',
								'dedup_asciifolding',
								'french_stemmer',
							],
						],
					],
				],
			],
		];
	}
}
