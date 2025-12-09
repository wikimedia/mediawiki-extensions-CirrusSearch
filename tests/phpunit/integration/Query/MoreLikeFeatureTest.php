<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use LinkCacheTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Wikimedia\TestingAccessWrapper;

/**
 * Test More Like This keyword feature.
 *
 * @license GPL-2.0-or-later
 *
 * @covers \CirrusSearch\Query\MoreLikeFeature
 * @covers \CirrusSearch\Query\MoreLikeThisFeature
 * @covers \CirrusSearch\Query\MoreLikeTrait
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class MoreLikeFeatureTest extends CirrusIntegrationTestCase {
	use SimpleKeywordFeatureTestTrait;
	use LinkCacheTestTrait;

	public static function applyProvider() {
		return [
			'morelike: doesnt eat unrelated queries' => [
				'other stuff',
				new \Elastica\Query\MatchAll(),
				false,
				MoreLikeFeature::class,
			],
			'morelike: is a queryHeader but ideally should not' => [
				'other stuff morelike:Test',
				new \Elastica\Query\MatchAll(),
				false,
				MoreLikeFeature::class,
			],
			'morelire: no query given for unknown page' => [
				'morelike:Does not exist or at least I hope not',
				null,
				true,
				MoreLikeFeature::class,
			],
			'morelike: single page' => [
				'morelike:Some page',
				( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '12345' ],
					] ),
				true,
				MoreLikeFeature::class,
			],
			'morelike: multi page' => [
				'morelike:Some page|Other page',
				( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '23456' ],
						[ '_id' => '12345' ],
					] ),
				true,
				MoreLikeFeature::class
			],
			'morelike: multi page with only one valid' => [
				'morelike:Some page|Does not exist or at least I hope not',
				( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '12345' ],
					] ),
				true,
				MoreLikeFeature::class
			],
			'morelikethis: doesnt eat unrelated queries' => [
				'other stuff',
				new \Elastica\Query\MatchAll(),
				false,
				MoreLikeThisFeature::class,
			],
			'morelikethis: can be combined' => [
				'other stuff morelikethis:"Some page" and other stuff',
				self::wrapInMust( ( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '12345' ],
					] ) ),
				true,
				MoreLikeThisFeature::class,
				'other stuff and other stuff'
			],
			'morelikethis: no query given for unknown page' => [
				'morelikethis:"Does not exist or at least I hope not"',
				null,
				true,
				MoreLikeThisFeature::class,
			],
			'morelikethis: single page' => [
				'morelikethis:"Some page"',
				self::wrapInMust( ( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '12345' ],
					] ) ),
				true,
				MoreLikeThisFeature::class,
			],
			'morelikethis: multi page' => [
				'morelikethis:"Some page|Other page"',
				self::wrapInMust( ( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '23456' ],
						[ '_id' => '12345' ],
					] ) ),
				true,
				MoreLikeThisFeature::class,
			],
			'morelikethis: multi page with only one valid' => [
				'morelikethis:"Some page|Does not exist or at least I hope not"',
				self::wrapInMust( ( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '12345' ],
					] ) ),
				true,
				MoreLikeThisFeature::class,
			],
		];
	}

	/**
	 * @dataProvider applyProvider
	 */
	public function testApply( $term, $expectedQuery, $mltUsed, $featureClass, $remainingText = '' ) {
		// Inject fake pages for MoreLikeTrait::collectTitles() to find
		$fakeTitleIDs = [
			'Some page' => 12345,
			'Other page' => 23456
		];
		$titleFactory = $this->getMockBuilder( TitleFactory::class )
			->onlyMethods( [ 'newFromText' ] )
			->getMock();
		$titleFactory->method( 'newFromText' )->willReturnCallback(
			static function ( $text, $ns ) use ( $fakeTitleIDs ) {
				$ret = Title::newFromText( $text, $ns );
				// Force the article ID and the redirect flag to avoid DB queries.
				$ret->resetArticleID( $fakeTitleIDs[$text] ?? 0 );
				$wrapper = TestingAccessWrapper::newFromObject( $ret );
				$wrapper->mRedirect = false;
				return $wrapper->object;
			} );
		$this->setService( 'TitleFactory', $titleFactory );
		foreach ( $fakeTitleIDs as $titleText => $id ) {
			$this->addGoodLinkObject( $id, Title::newFromText( $titleText ) );
		}

		// @todo Use a HashConfig with explicit values?
		$config = new HashSearchConfig( [ 'CirrusSearchMoreLikeThisTTL' => 600 ], [ HashSearchConfig::FLAG_INHERIT ] );

		$context = new SearchContext( $config );

		// Finally run the test
		$feature = new $featureClass( $config );

		if ( $mltUsed ) {
			$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::hostWikiOnlyStrategy() );
		}

		$result = $feature->apply( $context, $term );

		$this->assertSame( $mltUsed, $context->isSyntaxUsed( 'more_like' ) );
		if ( $mltUsed ) {
			$this->assertGreaterThan( 0, $context->getCacheTtl() );
		} else {
			$this->assertSame( 0, $context->getCacheTtl() );
		}
		if ( $expectedQuery === null ) {
			$this->assertFalse( $context->areResultsPossible() );
		} else {
			$this->assertEquals( $expectedQuery, $context->getQuery() );
			if ( $expectedQuery instanceof \Elastica\Query\MatchAll ) {
				$this->assertSame( $term, $result, 'Term must be unchanged' );
			} else {
				$this->assertSame( $remainingText, $result, 'Term must be empty string' );
			}
		}
	}

	public function testExpandedData() {
		$config = new SearchConfig();
		$title = Title::newFromText( 'Some page' );

		// Force the article ID and the redirect flag to avoid DB queries.
		$title->resetArticleID( 12345 );
		$wrapper = TestingAccessWrapper::newFromObject( $title );
		$wrapper->mRedirect = false;
		$title = $wrapper->object;
		$this->addGoodLinkObject( 12345, $title );
		$titleFactory = $this->getMockBuilder( TitleFactory::class )
			->onlyMethods( [ 'newFromText' ] )
			->getMock();
		$titleFactory->method( 'newFromText' )
			->willReturnCallback( static function ( $text, $ns ) use ( $title ) {
				if ( $text === 'Some page' ) {
					return $title;
				}
				$ret = Title::newFromText( $text, $ns );
				$ret->resetArticleID( 0 );
				return $ret;
			} );
		$this->setService( 'TitleFactory', $titleFactory );
		$feature = new MoreLikeFeature( $config );

		$this->assertExpandedData(
			$feature,
			'morelike:Some page',
			[ $title ],
			[],
			$config
		);

		$this->assertExpandedData(
			$feature,
			'morelike:Some page|Title that doesnt exist',
			[ $title ],
			[],
			$config
		);

		$this->assertExpandedData(
			$feature,
			'morelike:Title that doesnt exist',
			[],
			[ [ 'cirrussearch-mlt-feature-no-valid-titles', 'morelike' ] ],
			$config
		);
	}

	private static function wrapInMust( AbstractQuery $query ): AbstractQuery {
		$boolQuery = new BoolQuery();
		$boolQuery->addMust( $query );
		return $boolQuery;
	}
}
