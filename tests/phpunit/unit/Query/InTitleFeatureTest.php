<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\QueryStringRegex\KeywordParser;
use CirrusSearch\Parser\QueryStringRegex\OffsetTracker;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\Fetch\FetchPhaseConfigBuilder;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\SearchQuery;
use Elastica\Query\BoolQuery;
use MediaWiki\MainConfigNames;

/**
 * @covers \CirrusSearch\Query\InTitleFeature
 * @covers \CirrusSearch\Query\BaseRegexFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class InTitleFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	public static function parseProvider() {
		$defaults = [
			'fields' => [ 'title', 'title.plain', 'redirect.title', 'redirect.title.plain' ],
			'default_operator' => 'AND',
			'allow_leading_wildcard' => true,
			'fuzzy_prefix_length' => 2,
			'rewrite' => 'top_terms_boost_1024',
		];
		return [
			'basic search' => [
				[ 'query_string' => $defaults + [
					'query' => 'bridge',
				] ],
				'bridge ',
				'intitle:bridge',
			],
			'fuzzy search' => [
				[ 'query_string' => $defaults + [
					'query' => 'bridge~2',
				] ],
				'bridge~2 ',
				'intitle:bridge~2',
			],
			'gracefully handles titles including ~' => [
				[ 'query_string' => $defaults + [
					'query' => 'this\~that',
				] ],
				'this~that ',
				'intitle:this~that',
			],
			'maintains provided quotes and limits to plain' => [
				[ 'query_string' => [
					'query' => '"something or other"',
					'fields' => [ 'title.plain', 'redirect.title.plain' ],
				] + $defaults ],
				'"something or other" ',
				'intitle:"something or other"',
			],
			'contains a star' => [
				[ 'query_string' => [
					'query' => 'zomg*',
					'fields' => [ 'title.plain', 'redirect.title.plain' ],
				] + $defaults ],
				'zomg* ',
				'intitle:zomg*'
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( array $expectedQuery, $expectedTerm, $term ) {
		$config = new HashSearchConfig( [
			MainConfigNames::LanguageCode => 'en',
			CirrusConfigNames::AllowLeadingWildcard => true,
		] );
		$feature = new InTitleFeature( $config );
		$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
		$this->assertFilter( $feature, $term, $expectedQuery, [], $config );
		$this->assertNoHighlighting( $feature, $term );

		$this->assertRemaining( $feature, $term, $expectedTerm );
	}

	public function testNegatingDoesntKeepTerm() {
		$feature = new InTitleFeature( new HashSearchConfig( [] ) );
		$this->assertRemaining( $feature, '-intitle:mediawiki', '' );
	}

	/**
	 * @dataProvider provideRegexQueries
	 */
	public function testRegex( $query, $expectedRemaining, $negated, $filterValue, $insensitive ) {
		$filterCallback = null;
		if ( $filterValue !== null ) {
			$filterCallback = function ( BoolQuery $x ) use ( $filterValue, $insensitive ) {
				$this->assertTrue( $x->hasParam( 'should' ) );
				$this->assertIsArray( $x->getParam( 'should' ) );
				$this->assertCount( 2, $x->getParam( 'should' ) );
				$regex = $x->getParam( 'should' )[0];
				$this->assertInstanceOf( SourceRegex::class, $regex );
				$this->assertEquals( $filterValue, $regex->getParam( 'regex' ) );
				$this->assertEquals( 'title.trigram', $regex->getParam( 'ngram_field' ) );
				$this->assertEquals( !$insensitive, $regex->getParam( 'case_sensitive' ) );
				$regex = $x->getParam( 'should' )[1];
				$this->assertInstanceOf( SourceRegex::class, $regex );
				$this->assertEquals( $filterValue, $regex->getParam( 'regex' ) );
				$this->assertEquals( 'redirect.title.trigram', $regex->getParam( 'ngram_field' ) );
				$this->assertEquals( !$insensitive, $regex->getParam( 'case_sensitive' ) );

				return true;
			};
		}

		$feature = new InTitleFeature( new HashSearchConfig(
			[
				CirrusConfigNames::EnableRegex => true,
				CirrusConfigNames::WikimediaExtraPlugin => [ 'regex' => [ 'use' => true ] ]
			],
			[ HashSearchConfig::FLAG_INHERIT ]
		) );

		$this->assertFilter( $feature, $query, $filterCallback, [] );
		$this->assertExpandedData( $feature, $query, [], [] );
		if ( $filterValue !== null ) {
			$parsedValue = [
				'type' => 'regex',
				'pattern' => $filterValue,
				'insensitive' => $insensitive,
			];
			$this->assertParsedValue( $feature, $query, $parsedValue, [] );
			$this->assertCrossSearchStrategy( $feature, $query, CrossSearchStrategy::hostWikiOnlyStrategy() );
			$highlightQuery = [
				'pattern' => $filterValue,
				'insensitive' => $insensitive
			];

			if ( !$negated ) {
				$this->assertHighlighting( $feature, $query,
					[ 'title.plain', 'redirect.title.plain' ],
					[ $highlightQuery, $highlightQuery ] );
			}
		}

		// TODO: remove, should be a parser test
		$this->assertRemaining( $feature, $query, $expectedRemaining );
	}

	public static function provideRegexQueries() {
		return [
			'supports simple regex' => [
				'intitle:/bar/',
				'',
				false,
				'bar',
				false,
			],
			'supports simple case insensitive regex' => [
				'intitle:/bar/i',
				'',
				false,
				'bar',
				true,
			],
			'supports negation' => [
				'-intitle:/bar/',
				'',
				true,
				'bar',
				false,
			],
			'supports negation simple case insensitive regex' => [
				'-intitle:/bar/i',
				'',
				true,
				'bar',
				true,
			],
			'do not unescape the regex' => [
				'intitle:/foo\/bar/',
				'',
				false,
				'foo\\/bar',
				false,
			],
			'do not unescape the regex and keep insensitive flag' => [
				'intitle:/foo\/bar/i',
				'',
				false,
				'foo\\/bar',
				true,
			],
			'if the last character of the pattern searched is "/"' => [
				'intitle:/\/Documentation\//',
				'',
				false,
				'\/Documentation\/',
				false,
			],
		];
	}

	public function testEmpty() {
		// TODO: remove, should be a parser test
		$feature = new InTitleFeature( new HashSearchConfig( [] ) );
		$this->assertNotConsumed( $feature, "foo bar" );
	}

	private function newRedirectScopeContext( HashSearchConfig $config, ?FetchPhaseConfigBuilder $fetchPhase = null ): SearchContext {
		$context = new SearchContext(
			$config, null, null, null, $fetchPhase,
			$this->createNoOpMock( CirrusSearchHookRunner::class )
		);
		$context->setRedirectScope( true );
		return $context;
	}

	private function parseNode( InTitleFeature $feature, string $term ): KeywordFeatureNode {
		$nodes = ( new KeywordParser() )->parse( $term, $feature, new OffsetTracker() );
		$this->assertCount( 1, $nodes );
		return $nodes[0];
	}

	private function mockBuilderContext( bool $redirectScope, ?FetchPhaseConfigBuilder $fetchPhase = null ): QueryBuildingContext {
		$context = $this->createMock( QueryBuildingContext::class );
		$context->method( 'isRedirectScope' )->willReturn( $redirectScope );
		if ( $fetchPhase !== null ) {
			$context->method( 'getHighlightFieldGenerator' )->willReturn( $fetchPhase );
		}
		return $context;
	}

	private function fieldsOf( \Elastica\Query\AbstractQuery $query ): array {
		return $query->toArray()['query_string']['fields'];
	}

	/**
	 * In redirect mode intitle: drops the redirect.title fields on both the live
	 * apply() path and the AST getFilterQuery() path, so each redirect document is
	 * matched only by its own title.
	 */
	public function testRedirectModeNonRegexDropsRedirectFields() {
		$config = new HashSearchConfig( [ 'LanguageCode' => 'en', 'CirrusSearchAllowLeadingWildcard' => true ] );
		$feature = new InTitleFeature( $config );

		$context = $this->newRedirectScopeContext( $config );
		$feature->apply( $context, 'intitle:bridge' );
		$this->assertSame( [ 'title', 'title.plain' ], $this->fieldsOf( $context->getFilters()[0] ) );

		// The plain-only branch (wildcards) drops redirect.title.plain too.
		$context = $this->newRedirectScopeContext( $config );
		$feature->apply( $context, 'intitle:zomg*' );
		$this->assertSame( [ 'title.plain' ], $this->fieldsOf( $context->getFilters()[0] ) );

		// AST mirror: the same drop, gated on QueryBuildingContext::isRedirectScope().
		$node = $this->parseNode( $feature, 'intitle:bridge' );
		$this->assertSame( [ 'title', 'title.plain' ],
			$this->fieldsOf( $feature->getFilterQuery( $node, $this->mockBuilderContext( true ) ) ) );
		$this->assertSame( [ 'title', 'title.plain', 'redirect.title', 'redirect.title.plain' ],
			$this->fieldsOf( $feature->getFilterQuery( $node, $this->mockBuilderContext( false ) ) ) );
	}

	/**
	 * In redirect mode the regex intitle: query and its highlight field both drop
	 * redirect.title, and the shared field set is not mutated across queries.
	 */
	public function testRedirectModeRegexDropsRedirectFieldsForQueryAndHighlight() {
		$config = new HashSearchConfig(
			[
				'CirrusSearchEnableRegex' => true,
				'CirrusSearchWikimediaExtraPlugin' => [ 'regex' => [ 'use' => true ] ],
				'CirrusSearchUseExperimentalHighlighter' => true,
				'CirrusSearchFragmentSize' => 100,
			],
			[ HashSearchConfig::FLAG_INHERIT ]
		);
		$feature = new InTitleFeature( $config );

		// Live path: a single field collapses booleanOr to one SourceRegex on title.
		$fetchPhase = new FetchPhaseConfigBuilder( $config, SearchQuery::SEARCH_TEXT );
		$context = $this->newRedirectScopeContext( $config, $fetchPhase );
		$feature->apply( $context, 'intitle:/foo/' );
		$regex = $context->getFilters()[0];
		$this->assertInstanceOf( SourceRegex::class, $regex );
		$this->assertSame( 'title.trigram', $regex->getParam( 'ngram_field' ) );
		$this->assertNotNull( $fetchPhase->getHLField( 'title.plain' ) );
		$this->assertNull( $fetchPhase->getHLField( 'redirect.title.plain' ) );

		// AST path mirrors the drop for both query and highlight.
		$node = $this->parseNode( $feature, 'intitle:/foo/' );
		$astFetchPhase = new FetchPhaseConfigBuilder( $config, SearchQuery::SEARCH_TEXT );
		$astContext = $this->mockBuilderContext( true, $astFetchPhase );
		$this->assertInstanceOf( SourceRegex::class, $feature->getFilterQuery( $node, $astContext ) );
		$hlFields = $feature->buildHighlightFields( $node, $astContext );
		$this->assertSame( [ 'title.plain' ], array_map( static fn ( $f ) => $f->getFieldName(), $hlFields ) );

		// A subsequent default-mode query still sees both fields: $this->fields was not mutated.
		$defaultContext = $this->mockBuilderContext( false, new FetchPhaseConfigBuilder( $config, SearchQuery::SEARCH_TEXT ) );
		$defaultRegex = $feature->getFilterQuery( $node, $defaultContext );
		$this->assertInstanceOf( BoolQuery::class, $defaultRegex );
		$this->assertCount( 2, $defaultRegex->getParam( 'should' ) );
		$defaultHlFields = $feature->buildHighlightFields( $node, $defaultContext );
		$this->assertSame( [ 'title.plain', 'redirect.title.plain' ],
			array_map( static fn ( $f ) => $f->getFieldName(), $defaultHlFields ) );
	}

	public function testDisabled() {
		$feature = new InTitleFeature( new HashSearchConfig( [] ) );
		$this->assertParsedValue( $feature, 'intitle:/test/',
			[
				'type' => 'regex',
				'pattern' => 'test',
				'insensitive' => false,
			],
			[ [ 'cirrussearch-feature-not-available', 'intitle regex' ] ] );
	}

}
