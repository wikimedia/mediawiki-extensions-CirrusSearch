<?php

namespace extensions\CirrusSearch\tests\phpunit\integration\Api;

use CirrusSearch\CirrusIntegrationTestCaseTrait;
use CirrusSearch\CirrusSearch;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\RevisionRecord;

/**
 * @group Database
 */
class QueryBuildDocumentTest extends \ApiTestCase {
	use CirrusIntegrationTestCaseTrait;

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
		$expectedMetadata = [
			'cluster_group' => 'my_group',
			'noop_hints' => [
				'version' => 'documentVersion',
			],
		];

		$this->getNonexistingTestPage( 'QueryBuildDocumentTest_Page' );
		$status = $this->editPage( \Title::newFromText( "QueryBuildDocumentTest_Page" ), self::CONTENT_FIRST_REV );
		/** @var RevisionRecord $firstRevision */
		$firstRevision = $status->getValue()['revision-record'];

		$status = $this->editPage( \Title::newFromText( "QueryBuildDocumentTest_Page" ), self::CONTENT_SECOND_REV );
		/** @var RevisionRecord $secondRevision */
		$secondRevision = $status->getValue()['revision-record'];

		// Case 1: test latest using pageids
		$data = $this->doApiRequest( [
				"action" => "query",
				"pageids" => $firstRevision->getPage()->getId(),
				"prop" => "cirrusbuilddoc",
				"cbbuilders" => "content"
			] );
		$pageId = $firstRevision->getPage()->getId();
		$doc = $data[0]["query"]["pages"][$pageId]["cirrusbuilddoc"];
		$expectedDoc = $this->expectedSecondDoc( $secondRevision, $firstRevision );
		// sadly we have to restrict the test case to the keys managed by CirrusSearch alone
		// having other extensions installed might fail the assertion otherwise.
		$doc = array_intersect_key( $doc, $expectedDoc );
		$this->assertEquals( $expectedDoc, $doc );
		$cirrusMetadata = $data[0]["query"]["pages"][$pageId]["cirrusbuilddoc_metadata"];

		$this->assertArrayHasKey( 'size_limiter_stats', $cirrusMetadata );
		// remove the stats as they depend on the doc size which might vary depending on the extensions
		// being present while testing
		unset( $cirrusMetadata['size_limiter_stats'] );
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
		$this->assertEquals( $expectedMetadata, $cirrusMetadata );
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
			'wiki' => \WikiMap::getCurrentWikiId(),
			'title' => 'QueryBuildDocumentTest Page',
			'timestamp' => \MWTimestamp::convert( TS_ISO_8601, $revision->getTimestamp() ),
			'create_timestamp' => \MWTimestamp::convert( TS_ISO_8601, $firstRevision->getTimestamp() ),
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
			'wiki' => \WikiMap::getCurrentWikiId(),
			'title' => 'QueryBuildDocumentTest Page',
			'timestamp' => \MWTimestamp::convert( TS_ISO_8601, $revision->getTimestamp() ),
			'create_timestamp' => \MWTimestamp::convert( TS_ISO_8601, $firstRevision->getTimestamp() ),
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
