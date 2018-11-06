<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\QueryParserFactory;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Query\Builder\ContextualFilter;
use CirrusSearch\Query\Builder\FilterBuilder;
use CirrusSearch\Query\PrefixFeature;
use PHPUnit\Framework\Assert;

/**
 * @covers \CirrusSearch\Search\SearchQuery
 * @covers \CirrusSearch\Search\SearchQueryBuilder
 * @covers \CirrusSearch\Search\SearchContext
 * @covers \CirrusSearch\Parser\AST\ParsedQuery
 */
class SearchQueryTest extends CirrusTestCase {

	public function provildeGetNamespaces() {
		return [
			'all' => [
				[],
				[],
				[]
			],
			'simple' => [
				[ NS_MAIN ],
				[],
				[ NS_MAIN ]
			],
			'all + specific' => [
				[],
				[ 'simple' => [ NS_MAIN ] ],
				[]
			],
			'specific + all' => [
				[ NS_MAIN ],
				[ 'simple' => [] ],
				[]
			],
			'specific + specific' => [
				[ NS_MAIN ],
				[ 'simple' => [ NS_HELP ] ],
				[ NS_MAIN, NS_HELP ]
			],
			'specific + specifics + specific' => [
				[ NS_MAIN ],
				[
					'specifics' => [ NS_HELP, NS_HELP_TALK ],
					'specific' => [ NS_CATEGORY ],
				],
				[ NS_MAIN, NS_HELP, NS_HELP_TALK, NS_CATEGORY ]
			],
			'specific + specifics + all' => [
				[ NS_MAIN ],
				[
					'specifics' => [ NS_HELP, NS_HELP_TALK ],
					'all' => [],
				],
				[]
			]
		];
	}

	/**
	 * @dataProvider provildeGetNamespaces
	 * @param int[] $initialNs
	 * @param int[] $namespacesInContextualFilters
	 * @param int[] $expected
	 * @throws \Exception
	 */
	public function testGetNamespaces( $initialNs, array $namespacesInContextualFilters, $expected ) {
		$builder = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), "foo" )
			->setInitialNamespaces( $initialNs );
		foreach ( $namespacesInContextualFilters as $name => $namespaces ) {
			$builder->addContextualFilter( $name,
				$this->getContextualFilter( $namespaces )
			);
		}
		$this->assertEquals( $expected, $builder->build()->getNamespaces() );
	}

	public function provideCrossSearchStrategy() {
		return [
			'simple' => [
				'test',
				[
					'CirrusSearchEnableCrossProjectSearch' => true,
					'CirrusSearchEnableAltLanguage' => true,
				],
				CrossSearchStrategy::allWikisStrategy(),
				CrossSearchStrategy::allWikisStrategy(),
				CrossSearchStrategy::allWikisStrategy(),
			],
			'simple but crossproject disabled by config' => [
				'test',
				[
					'CirrusSearchEnableCrossProjectSearch' => false,
					'CirrusSearchEnableAltLanguage' => true,
				],
				CrossSearchStrategy::allWikisStrategy(),
				new CrossSearchStrategy( false, true, true ),
				new CrossSearchStrategy( false, true, true ),
			],
			'simple but crosslanguage disabled by config' => [
				'test',
				[
					'CirrusSearchEnableCrossProjectSearch' => true,
					'CirrusSearchEnableAltLanguage' => false,
				],
				CrossSearchStrategy::allWikisStrategy(),
				new CrossSearchStrategy( true, false, true ),
				new CrossSearchStrategy( true, false, true ),
			],
			'simple but crossproject & crosslanguage disabled by config' => [
				'test',
				[
					'CirrusSearchEnableAltLanguage' => false,
					'CirrusSearchEnableCrossProjectSearch' => false,
				],
				CrossSearchStrategy::allWikisStrategy(),
				new CrossSearchStrategy( false, false, true ),
				new CrossSearchStrategy( false, false, true ),
			],
			'reduce to hostwiki' => [
				'test',
				[
					'CirrusSearchEnableCrossProjectSearch' => true,
					'CirrusSearchEnableAltLanguage' => true,
				],
				CrossSearchStrategy::hostWikiOnlyStrategy(),
				CrossSearchStrategy::hostWikiOnlyStrategy(),
				CrossSearchStrategy::hostWikiOnlyStrategy(),
			],
			'reduced by query' => [
				'local:test',
				[
					'CirrusSearchEnableCrossProjectSearch' => true,
					'CirrusSearchEnableAltLanguage' => true,
				],
				CrossSearchStrategy::allWikisStrategy(),
				CrossSearchStrategy::allWikisStrategy(),
				CrossSearchStrategy::hostWikiOnlyStrategy(),
			],
			'fine tuned' => [
				'test',
				[
					'CirrusSearchEnableCrossProjectSearch' => true,
					'CirrusSearchEnableAltLanguage' => true,
				],
				new CrossSearchStrategy( false, true, true ),
				new CrossSearchStrategy( false, true, true ),
				new CrossSearchStrategy( false, true, true ),
			],
		];
	}

	/**
	 * Test how crosswiki strategy is merged between:
	 * - what is requested from SearchQueryBuilder::setCrossProjectSearch/setCrossLanguageSearch/setWithExtraIndices
	 * - what is allowed in the config (SearchRequestBuilder::build())
	 * - what is allowed by the query syntax (ParsedQuery/SearchQuery::getCrossSearchStrategy())
	 * @dataProvider provideCrossSearchStrategy
	 * @param string $query
	 * @param array $config
	 * @param CrossSearchStrategy $callerStrategy
	 * @param CrossSearchStrategy $initialCrossSearchStrategy
	 * @param CrossSearchStrategy $expected
	 */
	public function testCrossSearchStrategy(
		$query,
		array $config,
		CrossSearchStrategy $callerStrategy,
		CrossSearchStrategy $initialCrossSearchStrategy,
		CrossSearchStrategy $expected
	) {
		$searchQuery = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( $config ), $query )
			->setCrossProjectSearch( $callerStrategy->isCrossProjectSearchSupported() )
			->setCrossLanguageSearch( $callerStrategy->isCrossLanguageSearchSupported() )
			->setExtraIndicesSearch( $callerStrategy->isExtraIndicesSearchSupported() )
			->build();
		$this->assertEquals( $expected, $searchQuery->getCrossSearchStrategy() );
		$this->assertEquals( $initialCrossSearchStrategy, $searchQuery->getInitialCrossSearchStrategy() );
	}

	/**
	 * @param int[]|null $namespaces
	 * @return ContextualFilter
	 */
	private function getContextualFilter( array $namespaces = null ) {
		return new class( $namespaces ) implements ContextualFilter {
			private $namespaces;
			public function __construct( $namespaces ) {
				$this->namespaces = $namespaces;
			}

			function populate( FilterBuilder $builder ) {
				Assert::fail();
			}

			function requiredNamespaces() {
				return $this->namespaces;
			}
		};
	}

	public function testBuilderWithDefaults() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableCrossProjectSearch' => true,
			'CirrusSearchEnableAltLanguage' => true,
		] );
		$defaults = SearchQueryBuilder::newFTSearchQueryBuilder( $config, 'test' )->build();
		$expectedParsedQuery = QueryParserFactory::newFullTextQueryParser( $config )->parse( 'test' );
		$this->assertEquals( $expectedParsedQuery, $defaults->getParsedQuery() );
		$this->assertFalse( $defaults->hasForcedProfile() );
		$this->assertEquals( CrossSearchStrategy::allWikisStrategy(), $defaults->getInitialCrossSearchStrategy() );
		$this->assertEquals( CrossSearchStrategy::allWikisStrategy(), $defaults->getCrossSearchStrategy() );
		$this->assertEquals( 'searchText', $defaults->getSearchEngineEntryPoint() );
		$this->assertEquals( [ NS_MAIN ], $defaults->getNamespaces() );
		$this->assertEquals( [ NS_MAIN ], $defaults->getInitialNamespaces() );
		$this->assertEquals( 'relevance', $defaults->getSort() );
		$this->assertEquals( 0, $defaults->getOffset() );
		$this->assertEquals( 10, $defaults->getLimit() );
		$this->assertEquals( CirrusDebugOptions::defaultOptions(), $defaults->getDebugOptions() );
		$this->assertEquals( $config, $defaults->getSearchConfig() );
		$this->assertEmpty( $defaults->getContextualFilters() );
		$this->assertTrue( $defaults->isWithDYMSuggestion() );
	}

	public function testBuilder() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableCrossProjectSearch' => true,
			'CirrusSearchEnableAltLanguage' => true,
		] );
		$builder = SearchQueryBuilder::newFTSearchQueryBuilder( $config, 'test' )
			->setExtraIndicesSearch( false )
			->setCrossLanguageSearch( false )
			->setCrossProjectSearch( false )
			->setInitialNamespaces( [ NS_MAIN, NS_HELP ] )
			->addForcedProfile( SearchProfileService::RESCORE, 'test' )
			->setOffset( 10 )
			->setLimit( 100 )
			->setDebugOptions( CirrusDebugOptions::forDumpingQueriesInUnitTests() )
			->setSort( 'size' )
			->setWithDYMSuggestion( false );
		$custom = $builder->build();
		$expectedParsedQuery = QueryParserFactory::newFullTextQueryParser( $config )->parse( 'test' );
		$this->assertEquals( $expectedParsedQuery, $custom->getParsedQuery() );
		$this->assertTrue( $custom->hasForcedProfile() );
		$this->assertEquals( 'test', $custom->getForcedProfile( SearchProfileService::RESCORE ) );
		$this->assertEquals( CrossSearchStrategy::hostWikiOnlyStrategy(), $custom->getInitialCrossSearchStrategy() );
		$this->assertEquals( CrossSearchStrategy::hostWikiOnlyStrategy(), $custom->getCrossSearchStrategy() );
		$this->assertEquals( 'searchText', $custom->getSearchEngineEntryPoint() );
		$this->assertEquals( [ NS_MAIN, NS_HELP ], $custom->getNamespaces() );
		$this->assertEquals( [ NS_MAIN, NS_HELP ], $custom->getInitialNamespaces() );
		$this->assertEquals( 'size', $custom->getSort() );
		$this->assertEquals( 10, $custom->getOffset() );
		$this->assertEquals( 100, $custom->getLimit() );
		$this->assertEquals( CirrusDebugOptions::forDumpingQueriesInUnitTests(), $custom->getDebugOptions() );
		$this->assertEquals( $config, $custom->getSearchConfig() );
		$this->assertEmpty( $custom->getContextualFilters() );

		// test that contextual filters force a hostwiki only crosswiki search
		$builder->setExtraIndicesSearch( true )
			->setCrossLanguageSearch( true )
			->setCrossProjectSearch( true )
			->addContextualFilter( 'prefix', PrefixFeature::asContextualFilter( 'test' ) );
		$custom = $builder->build();
		$this->assertEquals( CrossSearchStrategy::allWikisStrategy(), $custom->getInitialCrossSearchStrategy() );
		$this->assertEquals( CrossSearchStrategy::hostWikiOnlyStrategy(), $custom->getCrossSearchStrategy() );
		$this->assertNotEmpty( $custom->getContextualFilters() );
		$this->assertInstanceOf( ContextualFilter::class, $custom->getContextualFilters()['prefix'] );
	}

	public function testSearchContextFromDefaults() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableCrossProjectSearch' => true,
			'CirrusSearchEnableAltLanguage' => true,
		] );
		$context = SearchContext::fromSearchQuery(
			SearchQueryBuilder::newFTSearchQueryBuilder( $config, 'test' )->build() );
		$this->assertEquals( $config, $context->getConfig() );
		$this->assertEquals( [ NS_MAIN ], $context->getNamespaces() );
		$this->assertTrue( $context->suggestionEnabled() );
		$this->assertFalse( $context->getLimitSearchToLocalWiki() );
		$this->assertEmpty( $context->getFilters() );
		$this->assertEquals( $config->getProfileService()->getProfileName( SearchProfileService::RESCORE ),
			$context->getRescoreProfile() );
		$this->assertEquals( $config->getProfileService()->getProfileName( SearchProfileService::FT_QUERY_BUILDER ),
			$context->getFulltextQueryBuilderProfile() );
		$this->assertEmpty( $context->getSuggestPrefixes() );
		$this->assertEquals( 'test', $context->getOriginalSearchTerm() );
	}

	public function testSearchContextFromBuilder() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableCrossProjectSearch' => true,
			'CirrusSearchEnableAltLanguage' => true,
		] );
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $config, '~help:test prefix:help_talk:test' )
			->setInitialNamespaces( [ NS_MAIN ] )
			->setWithDYMSuggestion( false )
			->setExtraIndicesSearch( false )
			->addContextualFilter( 'prefix', PrefixFeature::asContextualFilter( 'category:test' ) )
			->addForcedProfile( SearchProfileService::RESCORE, 'foo' )
			->addForcedProfile( SearchProfileService::FT_QUERY_BUILDER, 'bar' )
			->build();
		$context = SearchContext::fromSearchQuery(
			$query
		);
		$this->assertEquals( $config, $context->getConfig() );
		// the help prefix overrides NS_MAIN
		// the prefix keyword will add NS_HELP_TALK
		// the contextual filter will then add NS_CATEGORY
		$this->assertEquals( [ NS_HELP, NS_HELP_TALK, NS_CATEGORY ], $context->getNamespaces() );
		$this->assertFalse( $context->suggestionEnabled() );
		$this->assertTrue( $context->getLimitSearchToLocalWiki() );
		$this->assertNotEmpty( $context->getFilters() );
		$this->assertEquals( 'foo', $context->getRescoreProfile() );
		$this->assertEquals( 'bar', $context->getFulltextQueryBuilderProfile() );
		$this->assertEquals( [ '~', 'help:' ], $context->getSuggestPrefixes() );
		$this->assertEquals( '~help:test prefix:help_talk:test', $context->getOriginalSearchTerm() );
	}

	public function testForProjectSearch() {
		$nbRes = rand( 1, 10 );
		$hostWikiConfig = new HashSearchConfig( [
			'CirrusSearchNumCrossProjectSearchResults' => $nbRes,
			'CirrusSearchEnableCrossProjectSearch' => true,
			'CirrusSearchRescoreProfiles' => [
				'foo' => [],
				'common' => []
			]
		] );
		$targetWikiConfig = new HashSearchConfig( [
			'_wikiID' => 'target',
			'CirrusSearchRescoreProfiles' => [
				'common' => []
			]
		] );

		$builder = SearchQueryBuilder::newFTSearchQueryBuilder( $hostWikiConfig, 'myquery' );
		$hostWikiQuery = $builder->build();
		$crossSearchQuery = SearchQueryBuilder::forCrossProjectSearch( $targetWikiConfig, $hostWikiQuery )->build();
		$this->assertEquals( $hostWikiQuery->getParsedQuery(), $crossSearchQuery->getParsedQuery() );
		$this->assertFalse( $crossSearchQuery->hasForcedProfile() );
		$this->assertEquals( CrossSearchStrategy::hostWikiOnlyStrategy(), $crossSearchQuery->getInitialCrossSearchStrategy() );
		$this->assertEquals( CrossSearchStrategy::hostWikiOnlyStrategy(), $crossSearchQuery->getCrossSearchStrategy() );
		$this->assertEquals( 'searchText', $crossSearchQuery->getSearchEngineEntryPoint() );
		$this->assertEquals( [ NS_MAIN ], $crossSearchQuery->getNamespaces() );
		$this->assertEquals( [ NS_MAIN ], $crossSearchQuery->getInitialNamespaces() );
		$this->assertEquals( 'relevance', $crossSearchQuery->getSort() );
		$this->assertEquals( 0, $crossSearchQuery->getOffset() );
		$this->assertEquals( $nbRes, $crossSearchQuery->getLimit() );
		$this->assertEquals( CirrusDebugOptions::defaultOptions(), $crossSearchQuery->getDebugOptions() );
		$this->assertEquals( $targetWikiConfig, $crossSearchQuery->getSearchConfig() );
		$this->assertEmpty( $crossSearchQuery->getContextualFilters() );
		$this->assertFalse( $crossSearchQuery->isWithDYMSuggestion() );

		$builder->setOffset( 10 );
		$builder->setLimit( 100 );
		$builder->addForcedProfile( SearchProfileService::RESCORE, 'foo' );
		$builder->setInitialNamespaces( [ NS_MAIN, NS_HELP, 100 ] );
		$builder->setSort( 'size' );
		$builder->setDebugOptions( CirrusDebugOptions::forDumpingQueriesInUnitTests() );

		$hostWikiQuery = $builder->build();
		$crossSearchQuery = SearchQueryBuilder::forCrossProjectSearch( $targetWikiConfig, $hostWikiQuery )->build();

		$this->assertEquals( $hostWikiQuery->getParsedQuery(), $crossSearchQuery->getParsedQuery() );
		$this->assertFalse( $crossSearchQuery->hasForcedProfile() );
		$this->assertEquals( CrossSearchStrategy::hostWikiOnlyStrategy(), $crossSearchQuery->getInitialCrossSearchStrategy() );
		$this->assertEquals( CrossSearchStrategy::hostWikiOnlyStrategy(), $crossSearchQuery->getCrossSearchStrategy() );
		$this->assertEquals( 'searchText', $crossSearchQuery->getSearchEngineEntryPoint() );
		$this->assertEquals( [ NS_MAIN, NS_HELP ], $crossSearchQuery->getNamespaces() );
		$this->assertEquals( [ NS_MAIN, NS_HELP ], $crossSearchQuery->getInitialNamespaces() );
		$this->assertEquals( 'size', $crossSearchQuery->getSort() );
		$this->assertEquals( 0, $crossSearchQuery->getOffset() );
		$this->assertEquals( $nbRes, $crossSearchQuery->getLimit() );
		$this->assertEquals( CirrusDebugOptions::forDumpingQueriesInUnitTests(), $crossSearchQuery->getDebugOptions() );
		$this->assertEquals( $targetWikiConfig, $crossSearchQuery->getSearchConfig() );
		$this->assertEmpty( $crossSearchQuery->getContextualFilters() );
		$this->assertFalse( $crossSearchQuery->isWithDYMSuggestion() );

		// Test that forced profiles do not propagate to the cross search query if they do not exist
		// on the target wiki. Forced profiles are only set using official API params, cirrus debug
		// params may still allow to force a specific profile using the overrides chain.
		$builder->addForcedProfile( SearchProfileService::RESCORE, 'common' );
		$hostWikiQuery = $builder->build();
		$crossSearchQuery = SearchQueryBuilder::forCrossProjectSearch( $targetWikiConfig, $hostWikiQuery )->build();
		$this->assertTrue( $crossSearchQuery->hasForcedProfile() );
	}
}
