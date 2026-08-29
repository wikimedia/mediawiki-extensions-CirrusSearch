<?php

namespace CirrusSearch\Api;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\Sanity\Checker;
use CirrusSearch\Sanity\CheckerException;
use CirrusSearch\Sanity\Remediator;
use Closure;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Page\WikiPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

/**
 * @covers \CirrusSearch\Api\CheckSanity
 */
class CheckSanityTest extends CirrusIntegrationTestCase {

	public function testNoProblems() {
		$output = $this->doApiRequest(
			[ 'from' => 0, 'limit' => 10, 'cluster' => 'default' ],
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
				'cluster' => 'default'
			],
			[
				static function ( Remediator $remediator ) {
					throw new CheckerException();
				}
			] );
	}

	protected function mockPage( int $namespaceId, int $pageId ) {
		$mock = $this->createMock( WikiPage::class );

		$mock->method( 'getID' )->willReturn( $pageId );
		$mock->method( 'getNamespace' )->willReturn( $namespaceId );

		return $mock;
	}

	public static function provideProblems() {
		return [
			[
				'errorType' => 'redirectInIndex',
				'fn' => static function ( $self, Remediator $remediator ) {
					$remediator->redirectInIndex(
						'42',
						$self->mockPage( NS_MAIN, 1 ),
						'content'
					);
				},
				'extraCallback' => static fn () => [
					'pageId' => 1,
					'namespaceId' => NS_MAIN,
					'indexName' => WikiMap::getCurrentWikiId() . '_content',
					'target' => [
						'pageId' => 42,
						'namespaceId' => NS_TALK,
						'indexName' => WikiMap::getCurrentWikiId() . '_general',
					],
				],
				'isRedirect' => true,
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
				'extraCallback' => static fn () => [
					'wrongIndexName' => WikiMap::getCurrentWikiId() . '_general',
					'indexName' => WikiMap::getCurrentWikiId() . '_content',
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
	public function testProblems( $errorType, $fn, $extraCallback = null, bool $isRedirect = false ) {
		$redirectLookup = $this->createMock( RedirectLookup::class );
		if ( $isRedirect ) {
			// We want to avoid these things reaching out into the database, so have to provide
			// a redirect explicitly.
			$target = Title::makeTitleSafe( NS_TALK, "Example" );
			$target->loadFromRow( (object)[
				'page_id' => 42,
			] );
			$redirectLookup->method( 'getRedirectTarget' )->willReturn( $target );
		}
		$this->setService( 'RedirectLookup', $redirectLookup );

		$pageStore = $this->createMock( PageStore::class );
		$pageStore->method( 'getPageForLink' )->willReturnCallback(
			// $lt is always a Title returned by our RedirectLookup mock above
			static fn ( Title $lt ) => $lt->toPageIdentity()
		);
		$this->setService( 'PageStore', $pageStore );

		$output = $this->doApiRequest(
			[ 'from' => 0, 'limit' => 10, 'cluster' => 'default' ],
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
		if ( $extraCallback !== null ) {
			$extra = $extraCallback();
			foreach ( $extra as $key => $value ) {
				$this->assertArrayHasKey( $key, $problem );
				$this->assertEquals( $value, $problem[$key] );
			}
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
		$createMock = function ( $remediator ) use ( $checkFns ) {
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

		$services = $this->getServiceContainer();

		$api = new class( $createMock, $services, $apiMain, "cirrus-check-sanity" ) extends CheckSanity {

			public function __construct(
				private readonly Closure $createMock,
				MediaWikiServices $services,
				ApiMain $apiMain,
				string $moduleName
			) {
				parent::__construct(
					$apiMain,
					$moduleName,
					$services->getPageStore(),
					$services->getRedirectLookup(),
				);
			}

			protected function makeChecker( string $cluster, Remediator $remediator ): Checker {
				return ( $this->createMock )( $remediator );
			}
		};
		$api->execute();
		return $api->getResult()->getResultData();
	}
}
