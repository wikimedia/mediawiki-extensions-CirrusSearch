<?php

namespace CirrusSearch;

use CirrusSearch\Job\IndexArchive;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;

class ArchiveChangeListenerTest extends CirrusIntegrationTestCase {

	public static function provideCases(): array {
		return [
			'with index deletes enabled a default cluster setup' => [
				true,
				[
					'CirrusSearchClusters' => [
						'mycluster' => [ '127.0.0.1' ],
					],
					'CirrusSearchDefaultCluster' => 'mycluster',
					'CirrusSearchWriteClusters' => null,
					'CirrusSearchReplicaGroup' => 'default',
				],
				true
			],
			'with index deletes disabled and default cluster setup' => [
				false,
				[
					'CirrusSearchClusters' => [
						'mycluster' => [ '127.0.0.1' ],
					],
					'CirrusSearchDefaultCluster' => 'mycluster',
					'CirrusSearchWriteClusters' => null,
					'CirrusSearchReplicaGroup' => 'default',
				],
				false
			],
			'with index deletes enabled and a writeable cluster for archive' => [
				true,
				[
					'CirrusSearchClusters' => [
						'mycluster' => [ '127.0.0.1' ],
					],
					'CirrusSearchDefaultCluster' => 'mycluster',
					'CirrusSearchWriteClusters' => [
						'default' => [],
						UpdateGroup::ARCHIVE => [ 'mycluster' ]
					],
					'CirrusSearchReplicaGroup' => 'default',
				],
				true
			],
			'with index deletes enabled and a no writeable cluster for archive' => [
				true,
				[
					'CirrusSearchClusters' => [
						'mycluster' => [ '127.0.0.1' ],
					],
					'CirrusSearchDefaultCluster' => 'mycluster',
					'CirrusSearchWriteClusters' => [
						'default' => [],
					],
					'CirrusSearchReplicaGroup' => 'default',
				],
				false
			],
		];
	}

	/**
	 * @covers \CirrusSearch\ArchiveChangeListener::onPageDeleteComplete
	 * @dataProvider provideCases
	 */
	public function testOnPageDeleteComplete( bool $withDeletesEnabled, array $config, bool $expectedJob ) {
		$now = 321;
		$pageId = 123;
		$page = $this->createMock( \WikiPage::class );
		$title = $this->createMock( Title::class );
		$page->method( 'getTitle' )->willReturn( $title );
		$logEntry = $this->createMock( \ManualLogEntry::class );
		$logEntry->method( 'getTimestamp' )->willReturn( MWTimestamp::convert( TS_MW, $now ) );
		$jobqueue = $this->createMock( \JobQueueGroup::class );

		$expectedJobParam = [
			"docId" => (string)$pageId,
			"update_kind" => "page_change",
			"root_event_time" => $now,
			"private_data" => true
		];
		$jobqueue->expects( $this->exactly( $expectedJob ? 1 : 0 ) )
			->method( 'lazyPush' )
			->with( new IndexArchive( $title, $expectedJobParam ) );
		$listener = new ArchiveChangeListener( $jobqueue,
			$this->newHashSearchConfig( [ 'CirrusSearchIndexDeletes' => $withDeletesEnabled ] + $config ) );
		$listener->onPageDeleteComplete( $page,
			$this->createNoOpMock( Authority::class ),
			"a reason", $pageId,
			$this->createNoOpMock( RevisionRecord::class ), $logEntry, 2 );
	}

	/**
	 * @covers \CirrusSearch\ArchiveChangeListener::onPageUndeleteComplete
	 * @dataProvider provideCases
	 */
	public function testOnPageUndeleteComplete( bool $withDeletesEnabled, $config, bool $expectedJob ) {
		$jobqueue = $this->createMock( \JobQueueGroup::class );
		$page = new PageIdentityValue( 124, 0, 'A_Restored_Page', false );
		$title = Title::castFromPageIdentity( $page );
		$restoredPageIds = [ 123, 124 ];
		$listener = new ArchiveChangeListener( $jobqueue,
			$this->newHashSearchConfig( [ 'CirrusSearchIndexDeletes' => $withDeletesEnabled ] + $config ) );
		$jobqueue->expects( $this->exactly( $expectedJob ? 1 : 0 ) )
			->method( 'lazyPush' )
			->with( new Job\DeleteArchive( $title, [ 'docIds' => $restoredPageIds, 'private_data' => true ] ) );
		$listener->onPageUndeleteComplete( $page,
			$this->createNoOpMock( Authority::class ),
			'a reason',
			$this->createNoOpMock( RevisionRecord::class ),
			$this->createNoOpMock( \ManualLogEntry::class ), 2, true, $restoredPageIds );
	}
}
