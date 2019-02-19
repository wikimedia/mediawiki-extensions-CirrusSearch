<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParseWarning;
use CirrusSearch\Parser\QueryStringRegex\KeywordParser;
use CirrusSearch\Parser\QueryStringRegex\OffsetTracker;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\Rescore\BoostFunctionBuilder;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;

/**
 * Assertions method for testing KeywordFeature implementation.
 */
class KeywordFeatureAssertions {
	/**
	 * @var \PHPUnit\Framework\TestCase
	 */
	private $testCase;

	/**
	 * KeywordFeatureAssertions constructor.
	 * @param \MediaWikiTestCase $testCase
	 */
	public function __construct( \PHPUnit\Framework\TestCase $testCase ) {
		$this->testCase = $testCase;
	}

	/**
	 * @return SearchContext
	 */
	private function mockContext( SearchConfig $config = null ) {
		$context = $this->testCase->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();
		if ( $config == null ) {
			$config = new SearchConfig();
		}
		$context->expects( $this->testCase->any() )->method( 'getConfig' )->willReturn( $config );
		$context->expects( $this->testCase->any() )->method( 'escaper' )
			->willReturn( new Escaper( 'en', true ) );

		return $context;
	}

	/**
	 * @param null $expectedQuery
	 * @param array|callback|null $warnings
	 * @param bool $negated
	 * @return SearchContext
	 */
	private function mockContextExpectingAddFilter(
		$expectedQuery = null,
		$negated = false,
		SearchConfig $config
	) {
		$context = $this->mockContext();

		$context->method( 'getConfig' )->willReturn( $config );
		if ( $expectedQuery === null ) {
			$context->expects( $this->testCase->never() )
				->method( 'addFilter' );
			$context->expects( $this->testCase->never() )
				->method( 'addNotFilter' );
		} else {
			if ( is_callable( $expectedQuery ) ) {
				$filterCallback = $expectedQuery;
			} else {
				if ( $expectedQuery instanceof AbstractQuery ) {
					$expectedQuery = $expectedQuery->toArray();
				}
				$filterCallback = function ( AbstractQuery $query ) use ( $expectedQuery ) {
					$this->testCase->assertEquals( $expectedQuery, $query->toArray() );
					return true;
				};
			}

			$context->expects( $this->testCase->once() )
				->method( $negated ? 'addNotFilter' : 'addFilter' )
				->with( $this->testCase->callback( $filterCallback ) );
		}
		$warnings = [];
		$context->method( 'addWarning' )
			->will( $this->testCase->returnCallback( function () use ( &$warnings ) {
				$warnings[] = array_filter( func_get_args() );
			} ) );

		$context->method( 'getWarnings' )
			->will( $this->testCase->returnCallback( function () use ( &$warnings ) {
				return $warnings;
			} ) );

		return $context;
	}

	/**
	 * @param BoostFunctionBuilder|callback|null $expectedQuery
	 * @param array|null $warnings
	 * @return SearchContext
	 */
	private function mockContextExpectingBoost( $expectedBoost = null, SearchConfig $config = null ) {
		$context = $this->mockContext( $config );

		if ( $expectedBoost === null ) {
			$context->expects( $this->testCase->never() )
				->method( 'addCustomRescoreComponent' );
		} else {
			if ( is_callable( $expectedBoost ) ) {
				$boostCallback = $expectedBoost;
			} else {
				$boostCallback = function ( BoostFunctionBuilder $actualBoost ) use ( $expectedBoost ) {
					$this->testCase->assertEquals( $expectedBoost, $actualBoost );
					return true;
				};
			}

			$context->expects( $this->testCase->once() )
				->method( 'addCustomRescoreComponent' )
				->with( $this->testCase->callback( $boostCallback ) );
		}
		$warnings = [];

		$context->method( 'addWarning' )
			->will( $this->testCase->returnCallback( function () use ( &$warnings ) {
				$warnings[] = array_filter( func_get_args() );
			} ) );

		$context->method( 'getWarnings' )
			->willReturn( $warnings );

		return $context;
	}

	/**
	 * @param KeywordFeature $feature
	 * @param array $expected
	 * @param string $term
	 */
	public function assertWarnings( KeywordFeature $feature, $expected, $term ) {
		$warnings = [];
		$context = $this->mockContext();
		$context->expects( $this->testCase->any() )
			->method( 'addWarning' )
			->will( $this->testCase->returnCallback( function () use ( &$warnings ) {
				$warnings[] = array_filter( func_get_args() );
			} ) );
		$feature->apply( $context, $term );
		$this->testCase->assertEquals( $expected, $warnings );
	}

	/**
	 * Assert the value returned by KeywordFeature::getParsedValue
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array|null $expected
	 * @param array|null $expectedWarnings (null to disable warnings check)
	 */
	public function assertParsedValue( KeywordFeature $feature, $term, $expected, $expectedWarnings = null ) {
		$parser = new KeywordParser();
		$node = $this->getParsedKeyword( $term, $feature, $parser );
		if ( $expected === null ) {
			$this->testCase->assertNull( $node->getParsedValue() );
		} else {
			$this->testCase->assertNotNull( $node->getParsedValue() );
			$this->testCase->assertEquals( $expected, $node->getParsedValue() );
		}
		if ( $expectedWarnings !== null ) {
			$actualWarnings = $this->extractWarnings( $parser );
			$this->testCase->assertEquals( $expectedWarnings, $actualWarnings );
		}
	}
	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array $expected
	 * @param array|null $expectedWarnings (null to disable warnings check)
	 * @param SearchConfig|null $config (if null will run with an empty SearchConfig)
	 */
	public function assertExpandedData(
		KeywordFeature $feature,
		$term,
		array $expected,
		array $expectedWarnings = null,
		SearchConfig $config = null
	) {
		$node = $this->getParsedKeyword( $term, $feature );
		if ( $config === null ) {
			$config = new HashSearchConfig( [] );
		}

		$parser = new KeywordParser();
		$this->testCase->assertEquals( $expected, $feature->expand( $node, $config, $parser ) );
		if ( $expectedWarnings !== null ) {
			// Use KeywordParser as a WarningCollector
			$actualWarnings = $this->extractWarnings( $parser );
			$this->testCase->assertEquals( $expectedWarnings, $actualWarnings );
		}
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param CrossSearchStrategy $expected
	 */
	public function assertCrossSearchStrategy(
		KeywordFeature $feature,
		$term,
		CrossSearchStrategy $expected
	) {
		$parser = new KeywordParser();
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertCount( 1, $nodes,
			"A single keyword expression must be provided for this test" );
		$node = $nodes[0];
		if ( $node instanceof NegatedNode ) {
			$node = $node->getChild();
		}
		$this->testCase->assertEquals( $expected, $feature->getCrossSearchStrategy( $node ) );
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array|callable|null $filter
	 * @param array|null $warnings
	 * @param SearchConfig|null $config
	 */
	public function assertFilter(
		KeywordFeature $feature,
		$term,
		$filter = null,
		array $warnings = null,
		SearchConfig $config = null
	) {
		if ( $config === null ) {
			$config = new SearchConfig();
		}
		$context =
			$this->mockContextExpectingAddFilter( $filter,
				$this->isNegated( $feature, $term ), $config );
		if ( $filter !== null ) {
			$context->expects( $this->testCase->never() )->method( 'setResultsPossible' );
		}
		$feature->apply( $context, $term );
		if ( $warnings != null ) {
			$this->testCase->assertEquals( $warnings, $context->getWarnings() );
		}

		$this->testCase->assertInstanceOf( FilterQueryFeature::class, $feature );
		/**
		 * @var FilterQueryFeature $feature
		 */

		$parser = new KeywordParser();
		$node = $this->getParsedKeyword( $term, $feature, $parser );
		$context =
			$this->mockBuilderContext( $feature->expand( $node, $config, $parser ), $config );

		if ( is_callable( $filter ) ) {
			$filterCallback = $filter;
		} else {
			if ( $filter instanceof AbstractQuery ) {
				$filter = $filter->toArray();
			}
			$filterCallback = function ( $query ) use ( $filter ) {
				if ( $query === null ) {
					$this->testCase->assertNull( $filter );
				} else {
					$this->testCase->assertInstanceOf( AbstractQuery::class, $query );
					$this->testCase->assertEquals( $filter, $query->toArray() );
				}

				return true;
			};
		}
		$this->testCase->assertTrue( $filterCallback( $feature->getFilterQuery( $node, $context ) ) );
		if ( $warnings !== null ) {
			$this->testCase->assertEquals( $warnings, $this->extractWarnings( $parser ) );
		}
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param callable|BoostFunctionBuilder|null $boostAssertion
	 * @param array|null $warnings
	 * @param SearchConfig|null $config
	 */
	public function assertBoost(
		KeywordFeature $feature,
		$term,
		$boostAssertion,
		$warnings = null,
		SearchConfig $config = null
	) {
		$this->testCase->assertInstanceOf( BoostFunctionFeature::class, $feature );

		if ( $config === null ) {
			$config = new SearchConfig();
		}

		$context = $this->mockContextExpectingBoost( $boostAssertion, $config );
		$feature->apply( $context, $term );
		if ( $warnings != null ) {
			$this->testCase->assertEquals( $warnings, $context->getWarnings() );
		}

		$parser = new KeywordParser();
		$node = $this->getParsedKeyword( $term, $feature, $parser );
		$data = $feature->expand( $node, $config, $parser );
		if ( $warnings !== null ) {
			$this->testCase->assertEquals( $warnings, $this->extractWarnings( $parser ) );
		}
		$builderContext = $this->mockBuilderContext( $data, $config );
		/**
		 * @var BoostFunctionFeature $boostingKeyword
		 */
		$boostingKeyword = $feature;
		$func = $boostingKeyword->getBoostFunctionBuilder( $node, $builderContext );
		if ( $boostAssertion == null ) {
			$this->testCase->assertNull( $func );
		} elseif ( is_callable( $boostAssertion ) ) {
			call_user_func( $boostAssertion, $func );
		} else {
			$this->testCase->assertEquals( $boostAssertion, $func );
		}
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array|null $expectedWarnings
	 */
	public function assertNoResultsPossible( KeywordFeature $feature, $term, array $expectedWarnings = null ) {
		$context = $this->mockContext();
		$context->expects( $this->testCase->atLeastOnce() )->method( 'setResultsPossible' )->with( false );
		$warnings = [];
		if ( $expectedWarnings !== null ) {
			$context->method( 'addWarning' )
				->will( $this->testCase->returnCallback( function ( ...$args ) use ( &$warnings ) {
					$warnings[] = array_filter( $args );
				} ) );
		}
		$feature->apply( $context, $term );
		if ( $expectedWarnings !== null ) {
			$this->testCase->assertEquals( $expectedWarnings, $warnings );
		}
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param array|string|null $highlightField
	 * @param array|null $higlightQuery
	 */
	public function assertHighlighting(
		KeywordFeature $feature,
		$term,
		$highlightField = null,
		array $higlightQuery = null
	) {
		$context = $this->mockContext();

		if ( $highlightField !== null && $highlightField !== [] && !$this->isNegated( $feature, $term ) ) {
			if ( is_string( $highlightField ) ) {
				$context->expects( $this->testCase->once() )
					->method( 'addHighlightField' )
					->with( $highlightField, $higlightQuery );
			} else {
				$this->testCase->assertTrue( is_array( $highlightField ) );
				$this->testCase->assertEquals( count( $highlightField ), count( $higlightQuery ),
					'must have the same number of highlightFields than $higlightQueries' );

				$calls = [];
				$mi = new \MultipleIterator();
				$mi->attachIterator( new \ArrayIterator( $highlightField ) );
				$mi->attachIterator( new \ArrayIterator( $higlightQuery ) );
				foreach ( $mi as $value ) {
					$calls[] = $value;
				}

				$mock = $context->expects( $this->testCase->exactly( count( $highlightField ) ) )
					->method( 'addHighlightField' );
				$mock->withConsecutive( ...$calls );
			}
		} else {
			$context->expects( $this->testCase->never() )
				->method( 'addHighlightField' );
		}
		$feature->apply( $context, $term );
	}

	/**
	 * Historical test to make sure that the keyword does not consume unrelated values
	 * @param KeywordFeature $feature
	 * @param string $term
	 */
	public function assertNotConsumed( KeywordFeature $feature, $term ) {
		$context = $this->mockContext();
		$this->testCase->assertEquals( $term, $feature->apply( $context, $term ) );
		$parser = new KeywordParser();
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertEmpty( $nodes );
	}

	/**
	 * Historical test to make sure that the keyword does not consume unrelated values
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @param string $remaining
	 */
	public function assertRemaining( KeywordFeature $feature, $term, $remaining ) {
		$context = $this->mockContext();
		$this->testCase->assertEquals( $remaining, $feature->apply( $context, $term ) );
	}

	/**
	 * @param KeywordParser $parser
	 * @param string $term
	 * @return KeywordFeatureNode
	 */
	private function getParsedKeyword( $term, KeywordFeature $feature, KeywordParser $parser = null ) {
		if ( $parser === null ) {
			$parser = new KeywordParser();
		}
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertCount( 1, $nodes,
			"A single keyword expression must be provided for this test" );
		$node = $nodes[0];
		if ( $node instanceof NegatedNode ) {
			$node = $node->getChild();
		}
		$this->testCase->assertInstanceOf( KeywordFeatureNode::class, $node );
		return $node;
	}

	/**
	 * @param KeywordFeature $feature
	 * @param string $term
	 * @return bool
	 */
	private function isNegated( KeywordFeature $feature, $term ) {
		$parser = new KeywordParser();
		$nodes = $parser->parse( $term, $feature, new OffsetTracker() );
		$this->testCase->assertCount( 1, $nodes,
			"A single keyword expression must be provided for this test" );
		$node = $nodes[0];
		return $node instanceof NegatedNode;
	}

	/**
	 * @param $data
	 * @param SearchConfig $config
	 * @return QueryBuildingContext
	 */
	private function mockBuilderContext( $data, SearchConfig $config ) {
		$mock = $this->testCase->getMockBuilder( QueryBuildingContext::class )
			->disableOriginalConstructor()
			->getMock();

		$mock->method( 'getKeywordExpandedData' )
			->willReturn( $data );
		$mock->method( 'getSearchConfig' )
			->willReturn( $config );
		return $mock;
	}

	/**
	 * @param KeywordParser $parser
	 * @return array
	 */
	protected function extractWarnings( $parser ) {
		return array_map( function ( ParseWarning $warning ) {
			return array_merge( [ $warning->getMessage() ], $warning->getMessageParams() );
		}, $parser->getWarnings() );
	}
}
