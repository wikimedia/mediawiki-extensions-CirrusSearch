<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusIntegrationTestCase;
use Elastica\Result;
use MediaWiki\Title\Title;

/**
 * @covers \CirrusSearch\Search\SemanticSearchResultBuilder
 * @covers \CirrusSearch\Search\TitleHelper
 * @group CirrusSearch
 * @todo Make this a unit test when moving away from Title(Factory)
 */
class SemanticSearchResultBuilderTest extends CirrusIntegrationTestCase {
	private const NESTED_FIELD = 'passage_chunk_embedding';
	private const SNIPPET_FIELD = 'text';
	private const ANCHOR_FIELD = 'section';

	/** @var array */
	private static $MINIMAL_HIT = [
		'_id' => 'doc_id_1',
		'_source' => [
			'namespace' => NS_MAIN,
			'namespace_text' => '',
			'title' => 'Test Page',
			'timestamp' => '2024-01-01T00:00:00Z',
		],
		'inner_hits' => [
			'passage_chunk_embedding' => [
				'hits' => [
					'hits' => []
				]
			]
		]
	];

	private function newBuilder( array $extraFields = [] ): SemanticSearchResultBuilder {
		return new SemanticSearchResultBuilder(
			self::newTitleHelper(),
			self::NESTED_FIELD,
			self::SNIPPET_FIELD,
			self::ANCHOR_FIELD,
			$extraFields
		);
	}

	public static function provideTest(): array {
		$cases = [
			'word_count' => [
				array_merge_recursive( self::$MINIMAL_HIT, [ 'fields' => [ 'text.word_count' => [ 432 ] ] ] ),
				[ 'wordCount' => 432 ],
			],
			'byte_size' => [
				array_merge_recursive( self::$MINIMAL_HIT, [ '_source' => [ 'text_bytes' => 298000 ] ] ),
				[ 'byteSize' => 298000 ],
			],
			'timestamp' => [
				self::$MINIMAL_HIT,
				[ 'timestamp' => '20240101000000' ],
			],
			'score' => [
				array_merge_recursive( self::$MINIMAL_HIT, [ '_score' => 3.424 ] ),
				[ 'score' => 3.424 ],
			],
			'text_snippet_from_inner_hits' => [
				array_replace_recursive( self::$MINIMAL_HIT, [
					'inner_hits' => [
						'passage_chunk_embedding' => [
							'hits' => [
								'hits' => [
									[ '_source' => [ 'text' => 'A relevant passage about trebuchets.' ] ]
								]
							]
						]
					]
				] ),
				[ 'textSnippet' => 'A relevant passage about trebuchets.' ],
			],
			'section_title_from_inner_hits' => [
				array_replace_recursive( self::$MINIMAL_HIT, [
					'inner_hits' => [
						'passage_chunk_embedding' => [
							'hits' => [
								'hits' => [
									[ '_source' => [ 'section' => 'History of Siege Weapons' ] ]
								]
							]
						]
					]
				] ),
				[
					'sectionTitle' => Title::makeTitle( NS_MAIN, 'Test Page' )
						->createFragmentTarget( 'History_of_Siege_Weapons' ),
				],
			],
			'first_inner_hit_takes_precedence' => [
				array_replace_recursive( self::$MINIMAL_HIT, [
					'inner_hits' => [
						'passage_chunk_embedding' => [
							'hits' => [
								'hits' => [
									[ '_source' => [ 'text' => 'First passage.' ] ],
									[ '_source' => [ 'text' => 'Second passage (ignored).' ] ],
								]
							]
						]
					]
				] ),
				[ 'textSnippet' => 'First passage.' ],
			],
			'extra_fields' => [
				array_merge_recursive( self::$MINIMAL_HIT, [
					'_source' => [ 'extra_field1' => [ 'foo' ], 'extra_field2' => 2 ]
				] ),
				[ 'extensionData' => [ 'extra_fields' => [ 'extra_field1' => [ 'foo' ], 'extra_field2' => 2 ] ] ],
			],
		];
		return array_map( static function ( array $v ) {
			$v[0] = new Result( $v[0] );
			return $v;
		}, $cases );
	}

	/**
	 * @dataProvider provideTest
	 * @param Result $hit
	 * @param array $expectedFieldValues
	 */
	public function test( Result $hit, array $expectedFieldValues ): void {
		$extraFields = isset( $expectedFieldValues['extensionData'] ) ? [ 'extra_field1', 'extra_field2' ] : [];
		$builder = $this->newBuilder( $extraFields );
		$result = $builder->build( $hit );

		foreach ( $expectedFieldValues as $field => $value ) {
			$getter = $this->getter( $field, gettype( $value ) );
			$this->assertEquals(
				$value,
				$getter( $result ),
				"value for field '$field' should match"
			);
		}
	}

	private function getter( string $field, string $type ): callable {
		return static function ( CirrusSearchResult $result ) use ( $field, $type ) {
			$method = ( $type === 'boolean' ? 'is' : 'get' ) . ucfirst( $field );
			return $result->$method();
		};
	}
}
