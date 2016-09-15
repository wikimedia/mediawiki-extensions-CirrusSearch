<?php
namespace CirrusSearch\Query;

class FileFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function parseProviderNumeric() {
		return [
			'numeric with no sign - same as >' => [
				[ 'range' => [
					'file_size' => [
						'gte' => '10240',
					],
				] ],
				'filesize:10',
			],
			'numeric with with no sign - exact match' => [
				[ 'match' => [
					'file_bits' => [
						'query' => '16',
					],
				] ],
				'filebits:16',
			],
			'numeric with >' => [
				[ 'range' => [
					'file_width' => [
						'gte' => '10',
					],
				] ],
				'filew:>10',
			],
			'numeric with <' => [
				[ 'range' => [
					'file_height' => [
						'lte' => '100',
					],
				] ],
				'fileh:<100',
			],
			'numeric with range' => [
				[ 'range' => [
					'file_resolution' => [
						'gte' => '200',
						'lte' => '300',
					],
				] ],
				'fileres:200,300',
			],
			'nothing' => [
				null,
				'fileres:',
			],
			'not a number' => [
				null,
				'filesize:blah',
			],
			'one of the two is bad' => [
				null,
				'filewidth:100,notnumber',
			],
			'another of the two is bad' => [
				null,
				'fileheight:notevenclose,100',
			],
		];
	}

	/**
	 * @dataProvider parseProviderNumeric
	 */
	public function testParseNumeric( $expected, $term ) {
		$context = $this->mockContextExpectingAddFilter( $expected );
		$feature = new FileNumericFeature();
		$feature->apply( $context, $term );
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
			],
			'nothing' => [
				null,
				'filetype: ',
			],
		];
	}

	/**
	 * @dataProvider parseProviderType
	 */
	public function testParseType( $expected, $term ) {
		$context = $this->mockContextExpectingAddFilter( $expected );
		$feature = new FileTypeFeature();
		$feature->apply( $context, $term );
	}
}