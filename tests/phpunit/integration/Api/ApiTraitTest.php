<?php

namespace CirrusSearch\Tests\Api;

use CirrusSearch\Api\ApiTrait;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\SearchConfig;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\Utils\MWTimestamp;

/**
 * Exercises the cirrus doc-id resolution in ApiTrait::determineCirrusDocId(), which
 * QueryCirrusDoc relies on.
 *
 * @group Database
 * @covers \CirrusSearch\Api\ApiTrait
 */
class ApiTraitTest extends CirrusIntegrationTestCase {

	private const BASE_TIME = 1700000000;

	protected function setUp(): void {
		parent::setUp();
		MWTimestamp::setFakeTime( self::BASE_TIME );
	}

	protected function tearDown(): void {
		MWTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	private function searchConfig(): SearchConfig {
		// @phan-suppress-next-line PhanTypeMismatchReturnSuperType
		return $this->getServiceContainer()->getConfigFactory()->makeConfig( 'CirrusSearch' );
	}

	/**
	 * @return array Two element [ docId, hasRedirects ] tuple from determineCirrusDocId().
	 */
	private function resolveDocId( PageIdentity $title, bool $followRedirects ): array {
		// Advance "now" past the (frozen) revision timestamps so the archive lookup matches.
		MWTimestamp::setFakeTime( self::BASE_TIME + 3600 );
		$harness = new class( $this->getTestUser()->getUser() ) {
			use ApiTrait;

			private User $user;

			public function __construct( User $user ) {
				$this->user = $user;
			}

			public function getUser() {
				return $this->user;
			}

			public function resolve( PageIdentity $title, bool $followRedirects ): array {
				return $this->determineCirrusDocId( $title, $followRedirects );
			}
		};
		return $harness->resolve( $title, $followRedirects );
	}

	/**
	 * Build a fresh title object whose getId() is 0 (page absent from SQL), bypassing any
	 * cached link state left over from creating/deleting the page.
	 */
	private function missingTitle( string $dbKey ): Title {
		$this->getServiceContainer()->getLinkCache()->clear();
		$title = Title::makeTitle( NS_MAIN, $dbKey );
		$this->assertSame( 0, $title->getId(), 'precondition: page must be missing from SQL' );
		return $title;
	}

	public function testRedirectScopeRecoversDeletedPageId() {
		$page = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, 'ApiTraitDeleted' ) );
		$realId = $this->editPage( $page, 'content' )->getNewRevision()->getPage()->getId();
		$this->deletePage( $page, 'test' );

		$result = $this->resolveDocId( $this->missingTitle( 'ApiTraitDeleted' ), false );

		$this->assertSame( [ $this->searchConfig()->makeId( $realId ), false ], $result );
		$this->assertNotSame( $this->searchConfig()->makeId( 0 ), $result[0] );
	}

	public function testRedirectScopeReturnsRedirectsOwnIdWhileDefaultChasesTarget() {
		$target = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, 'ApiTraitTarget' ) );
		$targetId = $this->editPage( $target, 'target content' )->getNewRevision()->getPage()->getId();

		$redirect = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, 'ApiTraitRedirect' ) );
		$redirectId = $this->editPage( $redirect, '#REDIRECT [[ApiTraitTarget]]' )
			->getNewRevision()->getPage()->getId();
		$this->deletePage( $redirect, 'test' );

		$config = $this->searchConfig();
		// Redirect scope stops at the deleted redirect and reports its own archived id.
		$this->assertSame(
			[ $config->makeId( $redirectId ), false ],
			$this->resolveDocId( $this->missingTitle( 'ApiTraitRedirect' ), false )
		);
		// The default path still chases the redirect through to the live target.
		$this->assertSame(
			[ $config->makeId( $targetId ), true ],
			$this->resolveDocId( $this->missingTitle( 'ApiTraitRedirect' ), true )
		);
	}

	public function testRedirectScopeReturnsNullForNeverExistedPage() {
		$result = $this->resolveDocId( $this->missingTitle( 'ApiTraitNeverExisted' ), false );

		$this->assertSame( [ null, false ], $result );
	}
}
