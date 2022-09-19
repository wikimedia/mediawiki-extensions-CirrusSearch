<?php

namespace CirrusSearch\BuildDocument;

use Elastica\Document;
use MediaWiki\Revision\RevisionRecord;
use Title;
use WikiPage;

/**
 * @group Database
 * @covers \CirrusSearch\BuildDocument\DefaultPageProperties
 */
class DefaultPagePropertiesIntegrationTest extends \MediaWikiIntegrationTestCase {

	private function buildDoc( WikiPage $page, RevisionRecord $revision ): ?Document {
		$doc = new Document( null, [] );
		// Using the real database here to test integration from
		// editing real pages.
		$props = new DefaultPageProperties(
			$this->getServiceContainer()->getDBLoadBalancer()->getConnection( DB_REPLICA )
		);
		$props->initialize( $doc, $page, $revision );
		$props->finishInitializeBatch();
		$props->finalize( $doc, $page->getTitle(), $revision );
		return $doc;
	}

	public function testCreateTimestamp() {
		$pageName = 'testCreateTimestamp' . mt_rand();
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( Title::newFromText( $pageName ) );

		// Control time to ensure the revision timestamps differ
		$currentTime = 12345;
		\MWTimestamp::setFakeTime( static function () use ( &$currentTime ) {
			return $currentTime;
		} );
		try {
			// first revision should match create timestamp with revision
			$status = $this->editPage( $pageName, 'phpunit' );
			$this->assertTrue( $status->isOk() );
			$created = wfTimestamp(
				TS_ISO_8601,
				$status->getValue()['revision-record']->getTimestamp()
			);
			// Double check we are actually controlling the clock
			$this->assertEquals( wfTimestamp( TS_ISO_8601, $currentTime ), $created );
			$doc = $this->buildDoc( $page, $this->createMock( RevisionRecord::class ) );
			$this->assertEquals( $created, $doc->get( 'create_timestamp' ) );

			// With a second revision the create timestamp should still be the old one.
			$currentTime += 42;
			$status = $this->editPage( $pageName, 'phpunit and maybe other things' );
			$revision = $status->getValue()['revision-record'];
			$this->assertTrue( $status->isOk() );
			$doc = $this->buildDoc( $page, $revision );
			$this->assertEquals( $created, $doc->get( 'create_timestamp' ) );
		} finally {
			\MWTimestamp::setFakeTime( null );
		}
	}
}
