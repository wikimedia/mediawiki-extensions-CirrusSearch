<?php

namespace CirrusSearch\Job;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;

/**
 * @covers \CirrusSearch\Job\UpdateRedirectDocument
 */
class UpdateRedirectDocumentTest extends CirrusTestCase {

	public function testNewPageChangeUpdateUsesRevisionTimestamp() {
		$title = Title::makeTitle( NS_MAIN, 'Redirect' );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( MWTimestamp::convert( TS_MW, 1000 ) );

		$job = UpdateRedirectDocument::newPageChangeUpdate( $title, $revision, [] );

		$this->assertSame( 'cirrusSearchUpdateRedirectDocument', $job->getType() );
		$this->assertSame( CirrusTitleJob::PAGE_CHANGE, $job->getParams()[CirrusTitleJob::UPDATE_KIND] );
		$this->assertSame( 1000, $job->getParams()[CirrusTitleJob::ROOT_EVENT_TIME] );
	}

	public function testNewPageChangeUpdateFallsBackToNowWithoutRevision() {
		MWTimestamp::setFakeTime( 2000 );
		$job = UpdateRedirectDocument::newPageChangeUpdate( Title::makeTitle( NS_MAIN, 'Redirect' ), null, [] );
		$this->assertSame( 2000, $job->getParams()[CirrusTitleJob::ROOT_EVENT_TIME] );
	}

	public function testNewPageRefreshUpdate() {
		MWTimestamp::setFakeTime( 3000 );
		$job = UpdateRedirectDocument::newPageRefreshUpdate( Title::makeTitle( NS_MAIN, 'Redirect' ), [] );

		$this->assertSame( CirrusTitleJob::PAGE_REFRESH, $job->getParams()[CirrusTitleJob::UPDATE_KIND] );
		$this->assertSame( 3000, $job->getParams()[CirrusTitleJob::ROOT_EVENT_TIME] );
	}
}
