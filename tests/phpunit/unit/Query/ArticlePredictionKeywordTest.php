<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;
use MediaWiki\Message\Message;
use Wikimedia\Message\ListType;

/**
 * @covers \CirrusSearch\Query\ArticlePredictionKeyword
 * @group CirrusSearch
 */
class ArticlePredictionKeywordTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	/**
	 * Helper method for turning raw ORES score data (as stored in the Cirrus document) into
	 * search terms, for analytics/debugging.
	 * @param array $rawTopicData The unprefixed content of the document's weighted_tags field
	 * @return array corresponding search term => ORES score (rounded to three decimals)
	 */
	private static function getTopicScores( array $rawTopicData ): array {
		$labelsToTerms = array_flip( ArticleTopicFeature::TERMS_TO_LABELS );
		$topicScores = [];
		foreach ( $rawTopicData as $rawTopic ) {
			[ $oresLabel, $scaledScore ] = explode( '|', $rawTopic );
			$topicId = $labelsToTerms[$oresLabel];
			$topicScores[$topicId] = (int)$scaledScore / 1000;
		}
		return $topicScores;
	}

	public function testGetTopicScores() {
		$rawTopicData = [ 'Culture.Visual arts.Visual arts*|123', 'History and Society.History|456' ];
		$topics = self::getTopicScores( $rawTopicData );
		$this->assertSame( [ 'visual-arts' => 0.123, 'history' => 0.456 ], $topics );
	}

	public static function parseProvider() {
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
					'keywords' => [ 'STEM.STEM*' ],
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
					'keywords' => [ 'STEM.STEM*' ],
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
					'keywords' => [ 'STEM.STEM*' ],
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
					'keywords' => [ 'Culture.Media.Media*', 'Culture.Media.Music' ],
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
			$config, null, null, null, null,
			$this->createNoOpMock( CirrusSearchHookRunner::class )
		);
		$feature = new ArticlePredictionKeyword();

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

	public static function provide_testParse_invalid() {
		return [
			'With articletopic' => [ 'articletopic', 'foo' ],
			'With drafttopic' => [ 'drafttopic', 'foo' ]
		];
	}

	/**
	 * @dataProvider provide_testParse_invalid
	 */
	public function testParse_invalid( string $keyword ) {
		$feature = new ArticlePredictionKeyword();
		$this->assertWarnings( $feature, [ [ 'cirrussearch-articleprediction-invalid-keyword',
											 Message::listParam( [ 'foo' ],
		ListType::COMMA, ), 1, $keyword
		] ], "$keyword:foo" );
		$this->assertNoResultsPossible( $feature, "$keyword:foo" );
	}

}
