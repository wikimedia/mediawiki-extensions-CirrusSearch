<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Search\CirrusIndexField;
use Elastica\Document;
use Elastica\JSON;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CirrusSearch\BuildDocument\DocumentSizeLimiter
 */
class DocumentSizeLimiterTest extends TestCase {
	private const PROFILES = [
		"no limit" => [],
		"simple" => [
			'field_types' => [
				'external_links' => 'keyword'
			],
			'max_field_size' => [
				'file_text' => 2,
				'external_links' => 3
			]
		],
		"with_limits" => [
			'field_types' => [
				'external_links' => 'keyword',
				'outgoing_links' => 'keyword'
			],
			'max_field_size' => [
				'file_text' => 2,
				'external_links' => 3
			],
			'fields' => [
				'outgoing_links' => 3,
				'auxiliary_text' => 10,
				'text' => 10,
				'source_text' => 10
			],
			'markup_template' => 'CirrusSearchOversizeDocument',
			'max_size' => 242 - ( 36 + 12 + 10 ) + 31,
		]
	];

	public function provideDocuments(): array {
		return [
			"no limit" => [
				"no limit",
				[
					"text" => "123",
					"external_links" => [ "link1" ]
				],
				[
					"text" => "123",
					"external_links" => [ "link1" ]
				],
				[
					'document' => [ 'original_length' => 41, 'new_length' => 41 ]
				]
			],
			"mandatory limits" => [
				"simple",
				[
					"text" => "123",
					"file_text" => "123",
					"external_links" => [ "l1", "l2", "l3" ]
				],
				[
					"text" => "123",
					"file_text" => "12",
					"external_links" => [ "l1" ]
				],
				[
					'document' => [ 'original_length' => 66, 'new_length' => 61 ],
					'mandatory_reduction' => [ 'file_text' => 1, 'external_links' => 4 ]
				]
			],
			"oversize" => [
				"with_limits",
				[
					"source_text" => "this one is saved",
					"text" => "1234567890 this should disappear",
					"auxiliary_text" => [
						"12345",
						"12345 should vanish",
						"should vanish",
					],
					"file_text" => "123",
					"external_links" => [ "l1", "l2", "l3" ],
					"outgoing_links" => [ "o1", "o2", "o3" ],
					"template" => []
				],
				[
					"source_text" => "this one is saved",
					"text" => "1234567890",
					"auxiliary_text" => [
						"12345",
						"12345",
					],
					"file_text" => "12",
					"external_links" => [ "l1" ],
					"outgoing_links" => [ "o1" ],
					"template" => [ "CirrusSearchOversizeDocument" ]
				],
				[
					'document' => [ 'original_length' => 242, 'new_length' => 214 ],
					'mandatory_reduction' => [ 'file_text' => 1, 'external_links' => 4 ],
					'oversize_reduction' => [ 'outgoing_links' => 4, 'auxiliary_text' => 27,'text' => 22 ]
				]
			]
		];
	}

	/**
	 * @dataProvider provideDocuments
	 */
	public function testResize( string $profile, array $sourceDoc, array $expectedDoc, array $expectedMetrics ) {
		$limiter = new DocumentSizeLimiter( self::PROFILES[$profile] );
		$doc = new Document( null, $sourceDoc );
		$metrics = $limiter->resize( $doc );
		$this->assertEquals( $expectedDoc, $doc->getData() );
		$this->assertEquals( $expectedMetrics, $metrics );
		$this->assertEquals( CirrusIndexField::getHint( $doc, DocumentSizeLimiter::HINT_DOC_SIZE_LIMITER_STATS ), $metrics );
	}

	public function testWmfProfile() {
		$profiles = require __DIR__ . '/../../../../profiles/DocumentSizeLimiterProfiles.config.php';
		$inputDoc = [
			'text' => str_repeat( "a", 1000000 ),
			'file_text' => str_repeat( "a", 1000000 ),
			'opening_text' => str_repeat( "a", 1000000 ),
			'auxiliary_text' => array_fill( 0, 2, str_repeat( "a", 500000 ) ),
			'outgoing_links' => array_fill( 0, 10000, str_repeat( 'l', 100 ) ),
			'external_links' => array_fill( 0, 10000, str_repeat( 'l', 100 ) ),
			'template' => array_fill( 0, 10000, str_repeat( 'l', 100 ) ),
			'headings' => array_fill( 0, 10000, str_repeat( 'l', 100 ) ),
			'source_text' => str_repeat( 'a', 1000000 ),
		];

		$limiter = new DocumentSizeLimiter( $profiles['default'] );
		$doc = new Document( null, $inputDoc );
		$limiter->resize( $doc );
		$this->assertEquals( $inputDoc, $doc->getData() );

		$limiter = new DocumentSizeLimiter( $profiles['wmf'] );
		$doc = new Document( null, $inputDoc );
		$limiter->resize( $doc );
		$wmfProfileDoc = $inputDoc;
		$wmfProfileDoc["opening_text"] = str_repeat( "a", 10000 );
		$wmfProfileDoc["file_text"] = str_repeat( "a", 51200 );
		$this->assertEquals( $wmfProfileDoc, $doc->getData() );

		$limiter = new DocumentSizeLimiter( $profiles['wmf_capped'] );
		$doc = new Document( null, $inputDoc );
		$stats = $limiter->resize( $doc );
		$len = strlen( JSON::stringify( $doc->getData(), \JSON_UNESCAPED_UNICODE, \JSON_UNESCAPED_SLASHES ) );
		$this->assertEquals( str_repeat( 'a', 1000000 ), $doc->get( "source_text" ) );
		$this->assertLessThanOrEqual( 4000000, $len );
		$this->assertArrayNotHasKey( 'source_text', $stats[DocumentSizeLimiter::OVERSIZE_REDUCTION_REDUCTION_BUCKET] );
		$this->assertEquals( [ "external_links", "outgoing_links", "auxiliary_text", "template" ],
			array_keys( $stats[DocumentSizeLimiter::OVERSIZE_REDUCTION_REDUCTION_BUCKET] ) );
		$this->assertContains( "CirrusSearchOversizeDocument", $doc->get( "template" ) );
	}
}
