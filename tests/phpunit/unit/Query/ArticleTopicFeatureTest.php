<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\ArticleTopicFeature
 * @group CirrusSearch
 */
class ArticleTopicFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	public function testGetTopicScores() {
		$rawTopicData = [ 'Culture.Visual arts.Visual arts*|123', 'History and Society.History|456' ];
		$topics = ArticleTopicFeature::getTopicScores( $rawTopicData );
		$this->assertSame( [ 'visual-arts' => 0.123, 'history' => 0.456 ], $topics );
	}

	public function parseProvider() {
		$term = static function ( string $topic, string $prefix ) {
			return [
				[
					'term' => [
						'weighted_tags' => [
							'value' => "classification.ores.$prefix/$topic",
							'boost' => 1.0,
						],
					],
				],
			];
		};
		$match = static function ( array $query ) {
			return [ 'bool' => [ 'must' => [ $query ] ] ];
		};
		$filter = static function ( array $query ) {
			return [ 'bool' => [
				'must' => [ [ 'match_all' => [] ] ],
				'filter' => [ [ 'bool' => [ 'must_not' => [ $query ] ] ] ],
			] ];
		};

		return [
			'basic search' => [
				'articletopic:stem',
				[
					'topics' => [ 'STEM.STEM*' ],
					'tag_prefix' => 'classification.ores.articletopic',
				],
				$match( [
					'dis_max' => [
						'queries' => $term( 'STEM.STEM*', 'articletopic' ),
					],
				] ),
			],
			'basic search with drafttopic' => [
				'drafttopic:stem',
				[
					'topics' => [ 'STEM.STEM*' ],
					'tag_prefix' => 'classification.ores.drafttopic',
				],
				$match( [
					'dis_max' => [
						'queries' => $term( 'STEM.STEM*', 'drafttopic' ),
					],
				] ),
			],
			'negated' => [
				'-articletopic:stem',
				[
					'topics' => [ 'STEM.STEM*' ],
					'tag_prefix' => 'classification.ores.articletopic',
				],
				$filter( [
					'dis_max' => [
						'queries' => $term( 'STEM.STEM*', 'articletopic' ),
					],
				] ),
			],
			'multiple topics' => [
				'articletopic:media|music',
				[
					'topics' => [ 'Culture.Media.Media*', 'Culture.Media.Music' ],
					'tag_prefix' => 'classification.ores.articletopic',
				],
				$match( [
					'dis_max' => [
						'queries' => array_merge(
							$term( 'Culture.Media.Media*', 'articletopic' ),
							$term( 'Culture.Media.Music', 'articletopic' )
						),
					],
				] ),
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( string $term, array $expectedParsedValue, array $expectedQuery ) {
		$config = new HashSearchConfig( [] );
		$context = new SearchContext(
			$config, null, null, null, null, $this->createMock( CirrusSearchHookRunner::class )
		);
		$feature = new ArticleTopicFeature();

		$this->assertParsedValue( $feature, $term, $expectedParsedValue );
		$this->assertRemaining( $feature, $term, '' );
		$feature->apply( $context, $term );
		$actualQuery = $context->getQuery()->toArray();
		// MatchAll is converted to an stdClass instead of an array
		array_walk_recursive( $actualQuery, static function ( &$node ) {
			if ( $node instanceof \stdClass ) {
				$node = [];
			}
		} );
		$this->assertSame( $expectedQuery, $actualQuery );
	}

	public function provide_testParse_invalid() {
		return [
			'With articletopic' => [ 'articletopic' ],
			'With drafttopic' => [ 'drafttopic' ]
		];
	}

	/**
	 * @dataProvider provide_testParse_invalid
	 */
	public function testParse_invalid( string $keyword ) {
		$feature = new ArticleTopicFeature();
		$this->assertWarnings( $feature, [ [ 'cirrussearch-articletopic-invalid-topic',
											 [ 'list' => [ 'foo' ], 'type' => 'comma' ], 1 ] ], "$keyword:foo" );
		$this->assertNoResultsPossible( $feature, "$keyword:foo" );
	}

}
