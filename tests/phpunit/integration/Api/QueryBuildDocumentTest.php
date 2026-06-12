<?php

namespace CirrusSearch\Tests\Api;

use CirrusSearch\CirrusIntegrationTestCaseTrait;
use CirrusSearch\CirrusSearch;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;

/**
 * @group Database
 */
class QueryBuildDocumentTest extends ApiTestCase {
	use CirrusIntegrationTestCaseTrait;

	private const PAGE_TITLE = 'QueryBuildDocumentTest test page';
	private const CONTENT_FIRST_REV = "== Head ==\n " .
			"First revision " .
			"[[http://test.local/1 ref1]] " .
			"[[Page1]] " .
			"[[Category:Category1]] " .
			"{{template1}} " .
			"{{DISPLAYTITLE:displayed title}} " .
			"{{DEFAULTSORT:default sort}}";

	private const CONTENT_SECOND_REV = "== Head ==\n" .
			"Second revision " .
			"[[http://test.local/2]] " .
			"[[Page1]] [[Page2]]" .
			"[[Category:Category2]] " .
			"{{template2}} " .
			"{{DISPLAYTITLE:displayed title 2}} " .
			"{{DEFAULTSORT:default sort 2}}";

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			MainConfigNames::SearchType => CirrusSearch::class,
			MainConfigNames::RestrictDisplayTitle => false,
			'CirrusSearchWikimediaExtraPlugin' => [ 'documentVersion' ],
			'CirrusSearchDefaultCluster' => 'my_replica',
			'CirrusSearchReplicaGroup' => 'my_group',
			'CirrusSearchClusters' => [
				[
					[ "connection info" ],
					'group' => 'my_group',
					'replica' => 'my_replica'
				]
			]
		] );
	}

	/**
	 * @covers \CirrusSearch\Api\QueryBuildDocument
	 */
	public function test_content_extraction() {
		$this->overrideConfigValues( [ 'CapitalLinks' => true ] );
		$expectedMetadata = [
			'cluster_group' => 'my_group',
			'noop_hints' => [
				'version' => 'documentVersion',
				'redirect_target' => 'equals',
			],
		];

		$page = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, self::PAGE_TITLE ) );

		$status = $this->editPage( $page, self::CONTENT_FIRST_REV );
		$firstRevision = $status->getNewRevision();
		$this->assertNotNull( $firstRevision );
		$pageId = $firstRevision->getPage()->getId();

		$status = $this->editPage( $page, self::CONTENT_SECOND_REV );
		$secondRevision = $status->getNewRevision();
		$this->assertNotNull( $secondRevision );

		// FIXME: Somehow, the parser cache can contain stale data at this point, and the test would fail.
		// See investigation attempts in I0b7c194d5f4f8fb45236268330c5862764449915 and
		// I81479dc521134433489bb17a714b4868598a94c8.
		// Just reset the ParserOutputAccess service for now. This can probably be re-evaluated once T342301
		// and T342428 have been resolved.
		$this->getServiceContainer()->resetServiceForTesting( 'ParserOutputAccess' );

		// Case 1: test latest using pageids
		$data = $this->doApiRequest( [
				"action" => "query",
				"pageids" => $pageId,
				"prop" => "cirrusbuilddoc",
				"cbbuilders" => "content"
			] );
		$doc = $data[0]["query"]["pages"][$pageId]["cirrusbuilddoc"];
		$expectedDoc = $this->expectedSecondDoc( $secondRevision, $firstRevision );
		// sadly we have to restrict the test case to the keys managed by CirrusSearch alone
		// having other extensions installed might fail the assertion otherwise.
		$doc = array_intersect_key( $doc, $expectedDoc );
		$this->assertEquals( $expectedDoc, $doc );
		$cirrusMetadata = $data[0]["query"]["pages"][$pageId]["cirrusbuilddoc_metadata"];

		$indexName = $cirrusMetadata['index_name'];
		// WikiMap::getCurrentWikiId() does not appear to return the same value while setting-up
		// the services and while running the assertion, relax the test to just make sure
		// that we do attempt to replace the __wikiid__ placeholder from the CirrusSearchIndexBaseName
		// config value
		$this->assertStringEndsWith( "_content", $indexName, "_content" );
		$this->assertStringStartsWith( WikiMap::getCurrentWikiDbDomain()->getDatabase(), $indexName );

		$this->assertArrayHasKey( 'size_limiter_stats', $cirrusMetadata );
		// remove the stats as they depend on the doc size which might vary depending on the extensions
		// being present while testing
		unset( $cirrusMetadata['size_limiter_stats'] );
		unset( $cirrusMetadata['index_name'] );
		$this->assertEquals( $expectedMetadata, $cirrusMetadata );

		// Case 2: test first using revids
		$data = $this->doApiRequest( [
			"action" => "query",
			"revids" => $firstRevision->getId(),
			"prop" => "cirrusbuilddoc",
			"cbbuilders" => "content"
		] );

		$doc = $data[0]["query"]["pages"][$pageId]["cirrusbuilddoc"];
		$expectedDoc = $this->expectedFirstDoc( $firstRevision, $firstRevision );
		$doc = array_intersect_key( $doc, $expectedDoc );
		$this->assertEquals( $expectedDoc, $doc );
		$cirrusMetadata = $data[0]["query"]["pages"][$pageId]["cirrusbuilddoc_metadata"];

		$this->assertArrayHasKey( 'size_limiter_stats', $cirrusMetadata );
		unset( $cirrusMetadata['size_limiter_stats'] );
		unset( $cirrusMetadata['index_name'] );
		$this->assertEquals( $expectedMetadata, $cirrusMetadata );

		// Case 3: Request both revids
		$data = $this->doApiRequest( [
			"action" => "query",
			"revids" => implode( '|', [
				$firstRevision->getId(),
				$secondRevision->getId()
			] ),
			"prop" => "cirrusbuilddoc",
			"cbbuilders" => "content"
		] );

		$warnings = $data[0]['warnings']['cirrusbuilddoc'];
		$this->assertCount( 1, $warnings );
		$revId = $data[0]['query']['pages'][$pageId]['cirrusbuilddoc']['version'];
		$this->assertEquals( $secondRevision->getId(), $revId );
	}

	/**
	 * @covers \CirrusSearch\Api\QueryBuildDocument
	 */
	public function test_redirect_marked_unrenderable_when_build_disabled() {
		$this->overrideConfigValues( [
			'CirrusSearchRedirectDocuments' => [ 'build' => false, 'use' => false ],
		] );
		[ , $redirectId ] = $this->createRedirect();

		$data = $this->doApiRequest( [
			"action" => "query",
			"pageids" => $redirectId,
			"prop" => "cirrusbuilddoc",
			"cbbuilders" => "content",
		] );
		$page = $data[0]["query"]["pages"][$redirectId];
		$this->assertTrue( $page["unrenderable"] ?? false );
		$this->assertArrayNotHasKey( "cirrusbuilddoc", $page );
	}

	/**
	 * @covers \CirrusSearch\Api\QueryBuildDocument
	 */
	public function test_redirect_document_built_when_build_enabled() {
		$this->overrideConfigValues( [
			'CirrusSearchRedirectDocuments' => [ 'build' => true, 'use' => false ],
		] );
		[ $targetTitle, $redirectId ] = $this->createRedirect();

		$data = $this->doApiRequest( [
			"action" => "query",
			"pageids" => $redirectId,
			"prop" => "cirrusbuilddoc",
			"cbbuilders" => "content",
		] );
		$page = $data[0]["query"]["pages"][$redirectId];
		$this->assertArrayNotHasKey( "unrenderable", $page );
		$doc = $page["cirrusbuilddoc"];
		$this->assertSame( 'redirect', $doc['page_type'] );
		$this->assertSame( $targetTitle->getText(), $doc['redirect_target']['title'] );

		$noopHints = $data[0]["query"]["pages"][$redirectId]["cirrusbuilddoc_metadata"]["noop_hints"];
		$this->assertSame( 'equals', $noopHints['redirect_target'] ?? null );
	}

	/**
	 * Create a target page and a redirect pointing at it.
	 * @return array{0:Title,1:int} the target title and the redirect's page id
	 */
	private function createRedirect(): array {
		$target = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, 'RedirDocTarget' ) );
		$this->editPage( $target, 'Target content' );

		$redirect = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, 'RedirDocAlpha' ) );
		$status = $this->editPage( $redirect, '#REDIRECT [[RedirDocTarget]]' );
		$redirectId = $status->getNewRevision()->getPage()->getId();

		// See parser-cache note in test_content_extraction().
		$this->getServiceContainer()->resetServiceForTesting( 'ParserOutputAccess' );

		return [ $target->getTitle(), $redirectId ];
	}

	/**
	 * @param RevisionRecord $revision
	 * @param RevisionRecord $firstRevision
	 * @return array
	 */
	private function expectedSecondDoc( RevisionRecord $revision, RevisionRecord $firstRevision ): array {
		return [
			'version' => $revision->getId(),
			'namespace' => 0,
			'namespace_text' => '',
			'wiki' => WikiMap::getCurrentWikiId(),
			'title' => self::PAGE_TITLE,
			'timestamp' => MWTimestamp::convert( TS_ISO_8601, $revision->getTimestamp() ),
			'create_timestamp' => MWTimestamp::convert( TS_ISO_8601, $firstRevision->getTimestamp() ),
			'category' => [ "Category2" ],
			'external_link' => [ "http://test.local/2" ],
			'outgoing_link' => [ "Page1", "Page2", "Template:Template2" ],
			'template' => [ "Template:Template2" ],
			'text' => "Second revision [[1]] Page1 Page2 Template:Template2",
			'source_text' => self::CONTENT_SECOND_REV,
			'text_bytes' => 172,
			'content_model' => 'wikitext',
			'language' => 'en',
			'heading' => [ 'Head' ],
			'opening_text' => null,
			'auxiliary_text' => [],
			'defaultsort' => "default sort 2",
			'display_title' => "displayed title 2"
		];
	}

	private function expectedFirstDoc( RevisionRecord $revision, RevisionRecord $firstRevision ): array {
		return [
			'version' => $revision->getId(),
			'namespace' => 0,
			'namespace_text' => '',
			'wiki' => WikiMap::getCurrentWikiId(),
			'title' => self::PAGE_TITLE,
			'timestamp' => MWTimestamp::convert( TS_ISO_8601, $revision->getTimestamp() ),
			'create_timestamp' => MWTimestamp::convert( TS_ISO_8601, $firstRevision->getTimestamp() ),
			'category' => [ "Category1" ],
			'external_link' => [ "http://test.local/1" ],
			'outgoing_link' => [ "Page1", "Template:Template1" ],
			'template' => [ "Template:Template1" ],
			'text' => "First revision [ref1] Page1 Template:Template1",
			'source_text' => self::CONTENT_FIRST_REV,
			'text_bytes' => 164,
			'content_model' => 'wikitext',
			'language' => 'en',
			'heading' => [ 'Head' ],
			'opening_text' => null,
			'auxiliary_text' => [],
			'defaultsort' => "default sort",
			'display_title' => "displayed title"
		];
	}
}
