<?php

namespace CirrusSearch;

use CirrusSearch\BuildDocument\BuildDocument;
use MediaWiki\Page\WikiPage;

/**
 * @covers \CirrusSearch\Updater
 * @group Database
 */
class UpdaterTest extends CirrusIntegrationTestCase {

	private function newUpdater( SearchConfig $config, array $onlyMethods ): Updater {
		$connection = $this->createMock( Connection::class );
		$connection->method( 'getConfig' )->willReturn( $config );
		return $this->getMockBuilder( Updater::class )
			->setConstructorArgs( [ $connection ] )
			->onlyMethods( $onlyMethods )
			->getMock();
	}

	public static function provideBuildFlag(): array {
		return [
			'build off deletes the redirect documents' => [ false, true ],
			'build on keeps the redirect documents' => [ true, false ],
		];
	}

	/**
	 * @dataProvider provideBuildFlag
	 */
	public function testUpdateFromTitleGatesRedirectDeletionOnBuild( bool $build, bool $expectDelete ) {
		$config = $this->newHashSearchConfig(
			[ 'CirrusSearchRedirectDocuments' => [ 'build' => $build, 'use' => false ] ] );

		$redirect = $this->createMock( WikiPage::class );
		$redirect->method( 'getId' )->willReturn( 42 );

		$updater = $this->newUpdater( $config, [ 'traceRedirects', 'updatePages', 'deletePages' ] );
		// The redirect resolves to an already-updated target (null), leaving only the redirect
		// page in the chain to be either deleted (build off) or preserved (build on).
		$updater->method( 'traceRedirects' )->willReturn( [ null, [ $redirect ] ] );
		$updater->expects( $this->never() )->method( 'updatePages' );

		if ( $expectDelete ) {
			$updater->expects( $this->once() )->method( 'deletePages' )
				->with( [], [ $config->makeId( 42 ) ] );
		} else {
			$updater->expects( $this->never() )->method( 'deletePages' );
		}

		$updater->updateFromTitle( $this->createMock( \MediaWiki\Title\Title::class ), null, null );
	}

	public function testUpdateRedirectDocumentIndexesExistingPageDirectly() {
		$config = $this->newHashSearchConfig(
			[ 'CirrusSearchRedirectDocuments' => [ 'build' => true, 'use' => false ] ] );
		$page = $this->getExistingTestPage();

		$updater = $this->newUpdater( $config, [ 'updatePages' ] );
		$updater->expects( $this->once() )->method( 'updatePages' )
			->with(
				$this->callback( static function ( $pages ) use ( $page ) {
					return count( $pages ) === 1
						&& $pages[0] instanceof WikiPage
						&& $pages[0]->getTitle()->equals( $page->getTitle() );
				} ),
				BuildDocument::INDEX_EVERYTHING,
				'page_change',
				123
			);

		$updater->updateRedirectDocument( $page->getTitle(), 'page_change', 123 );
	}

	public function testUpdateRedirectDocumentSkipsMissingPage() {
		$config = $this->newHashSearchConfig(
			[ 'CirrusSearchRedirectDocuments' => [ 'build' => true, 'use' => false ] ] );

		$updater = $this->newUpdater( $config, [ 'updatePages' ] );
		$updater->expects( $this->never() )->method( 'updatePages' );

		$updater->updateRedirectDocument( $this->getNonexistingTestPage()->getTitle(), 'page_change', 123 );
	}
}
