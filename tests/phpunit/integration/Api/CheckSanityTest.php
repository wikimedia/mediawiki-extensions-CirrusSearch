<?php

namespace CirrusSearch\Api;

use ApiMain;
use ApiUsageException;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\Sanity\Checker;
use CirrusSearch\Sanity\CheckerException;
use CirrusSearch\Sanity\Remediator;
use FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use RequestContext;
use WikiPage;

/**
 * @covers \CirrusSearch\Api\CheckSanity
 */
class CheckSanityTest extends CirrusIntegrationTestCase {

	public function testNoProblems() {
		$output = $this->doApiRequest(
			[ 'from' => 0, 'limit' => 10 ],
			// no problems
			[ static function ( $remediator ) {
			} ] );

		$this->assertArrayHasKey( 'wikiId', $output );
		$this->assertArrayHasKey( 'clusterGroup', $output );
		$this->assertArrayHasKey( 'problems', $output );
		$this->assertCount( 0, $output['problems'] );
	}

	public function testCheckerBlowsUp() {
		$this->expectException( ApiUsageException::class );
		$result = $this->doApiRequest(
			[
				'from' => 0,
				'limit' => 10,
			],
			[
				static function ( Remediator $remediator ) {
					throw new CheckerException();
				}
			] );
	}

	protected function mockPage( int $namespaceId, int $pageId, ?Title $redirectTitle = null ) {
		$mock = $this->createMock( WikiPage::class );

		$mock->method( 'getID' )->willReturn( $pageId );
		$mock->method( 'getNamespace' )->willReturn( $namespaceId );
		$mock->method( 'getRedirectTarget' )->willReturn( $redirectTitle );

		return $mock;
	}

	public static function provideProblems() {
		$wikiId = WikiMap::getCurrentWikiId();
		return [
			[
				'errorType' => 'redirectInIndex',
				'fn' => static function ( $self, Remediator $remediator ) {
					// We want to avoid these things reaching out into the database, so have to provide
					// a redirect explicitly.
					$target = Title::makeTitleSafe( NS_TALK, "Example" );
					$target->loadFromRow( (object)[
						'page_id' => 42,
					] );
					$remediator->redirectInIndex( $self->mockPage( NS_MAIN, 1, $target ) );
				},
				'extra' => [
					'pageId' => 1,
					'namespaceId' => NS_MAIN,
					'target' => [
						'pageId' => 42,
						'namespaceId' => NS_TALK,
						'indexName' => "${wikiId}_general",
					],
				],
			],
			[
				'errorType' => 'pageNotInIndex',
				'fn' => static function ( $self, Remediator $remediator ) {
					$remediator->pageNotInIndex( $self->mockPage( NS_MAIN, 1 ) );
				},
			],
			[
				'errorType' => 'oldDocument',
				'fn' => static function ( $self, Remediator $remediator ) {
					$remediator->oldDocument( $self->mockPage( NS_MAIN, 1 ) );
				},
			],
			[
				'errorType' => 'ghostPageInIndex',
				'fn' => static function ( $self, Remediator $remediator ) {
					$remediator->ghostPageInIndex( '1', Title::newMainPage() );
				},
			],
			[
				'errorType' => 'pageInWrongIndex',
				'fn' => static function ( $self, Remediator $remediator ) {
					$remediator->pageInWrongIndex( '1', $self->mockPage( NS_MAIN, 1 ), 'general' );
				},
				'extra' => [
					'wrongIndexName' => "${wikiId}_general",
					'indexName' => "${wikiId}_content",
				],
			],
			[
				'errorType' => 'oldVersionInIndex',
				'fn' => static function ( $self, Remediator $remediator ) {
					$remediator->oldVersionInIndex( '1', $self->mockPage( NS_MAIN, 1 ), 'content' );
				},
			]
		];
	}

	/**
	 * @dataProvider provideProblems
	 */
	public function testProblems( $errorType, $fn, array $extra = [] ) {
		$output = $this->doApiRequest(
			[ 'from' => 0, 'limit' => 10 ],
			[
				function ( $remediator ) use ( $fn ) {
					$fn( $this, $remediator );
				}
			] );

		$this->assertArrayHasKey( 'wikiId', $output );
		$this->assertArrayHasKey( 'clusterGroup', $output );
		$this->assertArrayHasKey( 'problems', $output );
		$this->assertCount( 1, $output['problems'] );
		$problem = $output['problems'][0];
		$this->assertProblem( $problem, $errorType );
		foreach ( $extra as $key => $value ) {
			$this->assertArrayHasKey( $key, $problem );
			$this->assertEquals( $value, $problem[$key] );
		}
	}

	private function assertProblem( array $problem, string $errorType ) {
		// Properties that must exist on all problems
		$required = [ 'indexName', 'errorType', 'pageId', 'namespaceId' ];
		foreach ( $required as $name ) {
			$this->assertArrayHasKey( $name, $problem );
		}
		$this->assertIsInt( $problem['pageId'] );
		$this->assertIsInt( $problem['namespaceId'] );
		$this->assertEquals( $errorType, $problem['errorType'] );
	}

	public function doApiRequest( array $params, array $checkFns ) {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest( $params ) );
		$apiMain = new ApiMain( $context );
		$self = $this;
		$createMock = function ( $remediator ) use ( $self, $checkFns ) {
			$mock = $this->createMock( Checker::class );
			$mock->method( 'check' )
				->willReturnCallback( static function () use ( $remediator, &$checkFns ) {
					$fn = array_shift( $checkFns );
					if ( $fn !== null ) {
						$fn( $remediator );
					}
				} );
			return $mock;
		};

		$api = new class( $createMock, $apiMain, "cirrus-check-sanity" ) extends CheckSanity {
			private $createMock;

			public function __construct( $createMock, ApiMain $apiMain, $moduleName ) {
				parent::__construct( $apiMain, $moduleName );
				$this->createMock = $createMock;
			}

			protected function makeChecker( string $cluster, Remediator $remediator ): Checker {
				return ( $this->createMock )( $remediator );
			}
		};
		$api->execute();
		return $api->getResult()->getResultData();
	}
}
