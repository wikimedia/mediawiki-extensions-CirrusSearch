<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\FTQueryClassifiersRepository;
use CirrusSearch\Parser\KeywordRegistry;
use CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser;
use CirrusSearch\Parser\QueryStringRegex\SearchQueryParseException;
use CirrusSearch\Search\Escaper;
use CirrusSearch\SearchConfig;

/**
 * @covers \CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\SearchQueryParseException
 * @group CirrusSearch
 */
class MaxQueryLengthExceptionsTest extends CirrusIntegrationTestCase {
	use SimpleKeywordFeatureTestTrait;

	/**
	 * @dataProvider provideMaxLength
	 */
	public function testMaxLength( int $maxQueryLen, string $query, bool $expectedToPass ) {
		$config = new HashSearchConfig( [] );

		$registry = new class( $config ) implements KeywordRegistry {
			private $config;

			public function __construct( SearchConfig $config ) {
				$this->config = $config;
			}

			public function getKeywords() {
				return [
					new InCategoryFeature( $this->config ),
					new InSourceFeature( $this->config ),
					new ArticleTopicFeature(),
					new PageIdFeature(),
					new HasRecommendationFeature(),
					new HasTemplateFeature(),
				];
			}
		};
		$parser = new QueryStringRegexParser( $registry, new Escaper( 'en', false ), 'all',
			new FTQueryClassifiersRepository( $config, $this->createCirrusSearchHookRunner() ),
			$this->namespacePrefixParser(), $maxQueryLen );

		try {
			$parser->parse( $query );
			if ( !$expectedToPass ) {
				$this->fail( 'Expected to fail' );
			}
			$this->assertTrue( true );
		} catch ( SearchQueryParseException $e ) {
			if ( $expectedToPass ) {
				$this->fail( 'Expected to pass, failed with' . $e->asStatus()->__toString() );
			}
			$hasMessage = $e->asStatus()->hasMessage( 'cirrussearch-query-too-long' )
				|| $e->asStatus()->hasMessage( 'cirrussearch-query-too-long-with-exemptions' );
			$this->assertTrue( $hasMessage, 'Unexpected error' );
		}
	}

	public function provideMaxLength() {
		// return value: [ length limit, query, expected to pass? ]
		yield [ 10, str_repeat( 'a', 10 ), true ];
		yield [ 10, str_repeat( 'a', 11 ), false ];

		$keywordTests = [ 'incategory', 'articletopic', 'pageid',
						  'hastemplate', 'hasrecommendation', 'insource' ];

		foreach ( $keywordTests as $exemptedKeyword ) {
			yield [ 10, "$exemptedKeyword:test " . str_repeat( 'a', 9 ), true ];
			yield [ 10, "-$exemptedKeyword:test " . str_repeat( 'a', 9 ), true ];
			yield [ 10, "$exemptedKeyword:test " . str_repeat( 'a', 10 ), false ];
			yield [ 10, "-$exemptedKeyword:test " . str_repeat( 'a', 10 ), false ];
		}
	}
}
