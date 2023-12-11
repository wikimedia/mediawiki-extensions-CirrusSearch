<?php

namespace CirrusSearch\Event;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Config\HashConfig;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageRecord;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\UUID\GlobalIdGenerator;

class PageRerenderSerializerTest extends CirrusTestCase {
	private const MOCK_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * @var GlobalIdGenerator
	 */
	private $globalIdGenerator;

	protected function setUp(): void {
		$this->globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$this->globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );
	}

	/**
	 * @covers \CirrusSearch\Event\PageRerenderSerializer::eventDataForPage
	 */
	public function testEventDataForPage() {
		$mainConfig = new HashConfig( [
			MainConfigNames::ServerName => 'myserver.unittest.local',
			MainConfigNames::CanonicalServer => 'https://myserver.unittest.local',
			MainConfigNames::ArticlePath => '/wiki/$1',
		] );
		$searchConfig = $this->newHashSearchConfig( [
			'CirrusSearchClusters' => [
				'site1-mygroup' => [ [], 'group' => 'my_group', 'replica' => 'site1' ]
			],
			'CirrusSearchReplicaGroup' => 'my_group',
			'CirrusSearchIndexBaseName' => '__wikiid__',
			'CirrusSearchDefaultCluster' => 'site1',
			'CirrusSearchNamespaceMappings' => [ 0 => 'content' ],
			// use HashSearchConfig ability to override the wiki rather than messing with the
			// global state to control how WikiMap behaves in unit test.
			'_wikiID' => 'mywiki'
		] );

		$titleString = 'MyPage';
		$titleFormatter = $this->createMock( TitleFormatter::class );

		$serializer = new PageRerenderSerializer( $mainConfig, $titleFormatter, $searchConfig,
			$this->globalIdGenerator );

		$page = $this->createMock( PageRecord::class );
		$page->method( 'getId' )->willReturn( 123 );
		$page->method( 'isRedirect' )->willReturn( false );
		$page->method( 'getNamespace' )->willReturn( 0 );

		$titleFormatter->method( 'getPrefixedDBkey' )
			->with( $page )->willReturn( $titleString );

		MWTimestamp::setFakeTime( 0 );
		$expectedEvent = [
			'$schema' => '/mediawiki/cirrussearch/page_rerender/1.0.0',
			'meta' => [
				'stream' => 'mediawiki.cirrussearch.page_rerender.v1',
				'uid' => '00000000-0000-0000-0000-000000000000',
				'request_id' => 'my_request_id',
				'domain' => 'myserver.unittest.local',
				'uri' => 'https://myserver.unittest.local/wiki/MyPage'
			],
			'reason' => 'my_reason',
			'dt' => '1970-01-01T00:00:00Z',
			'wiki_id' => 'mywiki',
			'cirrussearch_index_name' => 'mywiki_content',
			'cirrussearch_cluster_group' => 'my_group',
			'page_id' => 123,
			'page_title' => 'MyPage',
			'namespace_id' => 0,
			'is_redirect' => false
		];
		$actualEvent = $serializer->eventDataForPage( $page, 'my_reason', 'my_request_id' );
		$this->assertSame( $expectedEvent, $actualEvent );
	}
}
