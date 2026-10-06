<?php

namespace CirrusSearch;

use Elastica\Client;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\SearchRequestLog
 */
class SearchRequestLogTest extends CirrusIntegrationTestCase {

	public function testExtractHitsHighlights() {
		$log = TestingAccessWrapper::newFromObject(
			new SearchRequestLog( $this->createMock( Client::class ), 'test', 'full_text' ) );
		$hits = $log->extractHits( [ 'hits' => [ 'hits' => [
			[
				'_index' => 'wiki_content',
				'_id' => '1',
				'_score' => 2.0,
				'_source' => [ 'namespace' => 0, 'title' => 'Fulltext' ],
				'highlight' => [
					'title' => [ 'title fragment' ],
					'text' => [ 'first fragment', 'second fragment' ],
				],
			],
			[
				'_index' => 'wiki_content',
				'_id' => '2',
				'_score' => 1.0,
				'_source' => [ 'namespace' => 0, 'title' => 'Semantic' ],
				'inner_hits' => [ 'passage_chunk_embedding' => [ 'hits' => [ 'hits' => [
					[ 'highlight' => [ 'passage_chunk_embedding.text' => [ 'best passage' ] ] ],
					[ 'highlight' => [ 'passage_chunk_embedding.text' => [ 'other passage' ] ] ],
				] ] ] ],
			],
			[
				'_index' => 'wiki_content',
				'_id' => '3',
				'_score' => 0.5,
				'_source' => [ 'namespace' => 0, 'title' => 'Plain' ],
			],
		] ] ] );

		$this->assertSame(
			[ 'title' => 'title fragment', 'text' => 'first fragment' ],
			$hits[0]['highlight']
		);
		$this->assertSame(
			[ 'passage_chunk_embedding.text' => 'best passage' ],
			$hits[1]['highlight']
		);
		$this->assertArrayNotHasKey( 'highlight', $hits[2] );
	}
}
