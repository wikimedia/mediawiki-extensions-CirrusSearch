<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use MediaWiki\Config\HashConfig;

/**
 * @covers \CirrusSearch\Query\FileTypeFeature
 * @group CirrusSearch
 */
class FileFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	public function provideParseType() {
		return [
			'basic match' => [
				[
					'user_types' => [ 'office' ],
					'aliased' => [],
				],
				[
					'match' => [
						'file_media_type' => [
							'query' => 'office',
						],
					]
				],
				'filetype:office',
			],

			'bool or type match' => [
				[
					'user_types' => [ 'office', 'jpg' ],
					'aliased' => [],
				],
				[
					'bool' => [
						'minimum_should_match' => 1,
						'should' => [
							[
								'match' => [
									'file_media_type' => [
										'query' => 'office',
									],
								],
							],
							[
								'match' => [
									'file_media_type' => [
										'query' => 'jpg',
									],
								],
							],
						],
					]

				],
				'filetype:office|jpg'
			],

			'applies aliases' => [
				[
					'user_types' => [ 'doc' ],
					'aliased' => [ 'office' ],
				],
				[
					'bool' => [
						'minimum_should_match' => 1,
						'should' => [
							[
								'match' => [
									'file_media_type' => [
										'query' => 'office',
									],
								],
							],
							[
								'match' => [
									'file_media_type' => [
										'query' => 'doc',
									],
								],
							],
						],
					],
				],
				'filetype:doc'
			],

			'lowercases before checking aliases' => [
				[
					'user_types' => [ 'DoC' ],
					'aliased' => [ 'office' ],
				],
				[
					'bool' => [
						'minimum_should_match' => 1,
						'should' => [
							[
								'match' => [
									'file_media_type' => [
										'query' => 'office',
									],
								],
							],
							[
								'match' => [
									'file_media_type' => [
										'query' => 'DoC',
									],
								],
							],
						],
					],
				],
				'filetype:DoC'
			]
		];
	}

	/**
	 * @dataProvider provideParseType
	 */
	public function testParseType( $expectedParsed, $expectedQuery, $term ) {
		$config = new HashConfig( [ 'CirrusSearchFiletypeAliases' => [
			'doc' => 'office',
		] ] );
		$feature = new FileTypeFeature( $config );
		if ( $expectedQuery !== null ) {
			$this->assertParsedValue( $feature, $term, $expectedParsed, [] );
			$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
			$this->assertExpandedData( $feature, $term, [], [] );
		}
		$this->assertFilter( $feature, $term, $expectedQuery, [] );
	}

	public static function warningTypeProvider() {
		return [
			'too many conditions' => [
				[
					[ 'cirrussearch-feature-too-many-conditions', 'filetype', FileTypeFeature::MAX_CONDITIONS ],
				],
				[
					'user_types' => array_map( 'strval', range( 0, FileTypeFeature::MAX_CONDITIONS - 1 ) ),
					'aliased' => [],
				],
				'filetype:' . implode( '|', range( 0, 100 ) ),
			],
		];
	}

	/**
	 * @dataProvider warningTypeProvider
	 */
	public function testWarningType( $expectedWarnings, $expectedParsed, $term ) {
		$config = new HashConfig( [ 'CirrusSearchFiletypeAliases' => [] ] );
		$feature = new FileTypeFeature( $config );
		$this->assertParsedValue( $feature, $term, $expectedParsed, $expectedWarnings );
	}

}
