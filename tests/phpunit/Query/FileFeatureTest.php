<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;

/**
 * @covers \CirrusSearch\Query\FileNumericFeature
 * @covers \CirrusSearch\Query\FileTypeFeature
 * @group CirrusSearch
 */
class FileFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function parseProviderNumeric() {
		return [
			'numeric with no sign - same as >' => [
				[ 'range' => [
					'file_size' => [
						'gte' => '10240',
					],
				] ],
				[
					'sign' => 1,
					'value' => 10,
					'field' => 'file_size'
				],
				[],
				'filesize:10',
			],
			'filesize allows multi-argument' => [
				[ 'range' => [
					'file_size' => [
						'gte' => 125952,
						'lte' => 328704,
					]
				] ],
				[
					'sign' => 0,
					'range' => [ 123, 321 ],
					'field' => 'file_size'
				],
				[],
				'filesize:123,321',
			],
			'numeric with with no sign - exact match' => [
				[ 'match' => [
					'file_bits' => [
						'query' => '16',
					],
				] ],
				[
					'sign' => 0,
					'value' => 16,
					'field' => 'file_bits',
				],
				[],
				'filebits:16',
			],
			'numeric with >' => [
				[ 'range' => [
					'file_width' => [
						'gte' => '10',
					],
				] ],
				[
					'sign' => 1,
					'value' => 10,
					'field' => 'file_width',
				],
				[],
				'filew:>10',
			],
			'numeric with <' => [
				[ 'range' => [
					'file_height' => [
						'lte' => '100',
					],
				] ],
				[
					'sign' => -1,
					'value' => 100,
					'field' => 'file_height',
				],
				[],
				'fileh:<100',
			],
			'numeric with range' => [
				[ 'range' => [
					'file_resolution' => [
						'gte' => '200',
						'lte' => '300',
					],
				] ],
				[
					'sign' => 0,
					'range' => [ 200, 300 ],
					'field' => 'file_resolution',
				],
				[],
				'fileres:200,300',
			],
			'not a number' => [
				null,
				null,
				[ [ 'cirrussearch-file-numeric-feature-not-a-number', 'filesize', 'blah' ] ],
				'filesize:blah',
			],
			'one of the two is bad' => [
				null,
				null,
				[ [ 'cirrussearch-file-numeric-feature-not-a-number', 'filewidth', 'notnumber' ] ],
				'filewidth:100,notnumber',
			],
			'another of the two is bad' => [
				null,
				null,
				[ [ 'cirrussearch-file-numeric-feature-not-a-number', 'fileheight', 'notevenclose' ] ],
				'fileheight:notevenclose,100',
			],
		];
	}

	/**
	 * @dataProvider parseProviderNumeric
	 */
	public function testParseNumeric( $expected, $expectedParsedValue, $expectedWarnings, $term ) {
		$feature = new FileNumericFeature();

		if ( $expectedParsedValue !== false ) {
			$this->assertParsedValue( $feature, $term, $expectedParsedValue, $expectedWarnings );
			$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
			$this->assertExpandedData( $feature, $term, [], [] );
		}

		$this->assertFilter( $feature, $term, $expected, $expectedWarnings );
	}

	public function testNothing() {
		$this->assertNotConsumed( new FileNumericFeature(), 'fileres:' );
		$this->assertNotConsumed( new FileNumericFeature(), 'filetype:' );
	}

	public function parseProviderType() {
		return [
			'type match' => [
				[
					'match' => [
						'file_media_type' => [
							'query' => 'office',
						],
					]
				],
				'filetype:office',
			],
			'mime match phrase' => [
				[
					'match_phrase' => [
						'file_mime' => 'image/png',
					]
				],
				'filemime:"image/png"',
			],
			'mime match' => [
				[
					'match' => [
						'file_mime' => [
							'query' => 'pdf',
							'operator' => 'AND'
						],
					]
				],
				'filemime:pdf',
			]
		];
	}

	/**
	 * @dataProvider parseProviderType
	 */
	public function testParseType( $expected, $term ) {
		$feature = new FileTypeFeature();
		if ( $expected !== null ) {
			$this->assertParsedValue( $feature, $term, null, [] );
			$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
			$this->assertExpandedData( $feature, $term, [], [] );
		}
		$this->assertFilter( $feature, $term, $expected, [] );
	}

	public function warningNumericProvider() {
		return [
			'arguments must be numeric' => [
				[ [ 'cirrussearch-file-numeric-feature-not-a-number', 'filebits', 'celery' ] ],
				'filebits:celery'
			],
			'each argument in a multi-value must be a number' => [
				[
					[ 'cirrussearch-file-numeric-feature-not-a-number', 'fileheight', 'something' ],
					[ 'cirrussearch-file-numeric-feature-not-a-number', 'fileheight', 'voodoo' ]
				],
				'fileheight:something,voodoo',
			],
			'multi-argument with a sign is invalid' => [
				[ [ 'cirrussearch-file-numeric-feature-multi-argument-w-sign', 'fileh', '200,400' ] ],
				'fileh:>200,400',
			],
			'unparsable output must still be reported' => [
				[ [ 'cirrussearch-file-numeric-feature-not-a-number', 'filesize', '>' ] ],
				'filesize:>',
			],
		];
	}

	/**
	 * @dataProvider warningNumericProvider
	 */
	public function testWarningNumeric( $expected, $term ) {
		$this->assertParsedValue( new FileNumericFeature(), $term, null, $expected );
	}
}
