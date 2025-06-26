<?php

namespace CirrusSearch;

use CirrusSearch\BuildDocument\Completion\DefaultSortSuggestionsBuilder;
use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use CirrusSearch\BuildDocument\Completion\SuggestScoringMethodFactory;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

/**
 * @covers \CirrusSearch\BuildDocument\Completion\SuggestBuilder
 */
class SuggestBuilderIntegrationTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'LinkBatchFactory', $this->createMock( LinkBatchFactory::class ) );
	}

	/**
	 * @todo Make this a unit test when DI becomes possible.
	 */
	public function testCrossNSRedirects() {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturnCallback( static function ( $ns, $title, $fragment = '', $interwiki = '' ) {
			$ret = Title::makeTitle( $ns, $title, $fragment, $interwiki );
			$ret->resetArticleID( 0 );
			return $ret;
		} );
		$this->setService( 'TitleFactory', $titleFactory );
		$builder = $this->buildBuilder();
		$score = 10;
		$doc = [
			'id' => 123,
			'title' => 'Navigation',
			'namespace' => 12,
			'redirect' => [
				[ 'title' => 'WP:HN', 'namespace' => 0 ],
				[ 'title' => 'WP:NAV', 'namespace' => 0 ],
			],
			'incoming_links' => $score
		];

		$score = (int)( SuggestBuilder::CROSSNS_DISCOUNT * $score );

		$expected = [
			[
				'source_doc_id' => 123,
				'target_title' => [
					'title' => 'Navigation',
					'namespace' => 12
				],
				'suggest' => [
					'input' => [ 'WP:HN' ],
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'WP:HN' ],
					'weight' => $score
				],
			],
			[
				'source_doc_id' => 123,
				'target_title' => [
					'title' => 'Navigation',
					'namespace' => 12
				],
				'suggest' => [
					'input' => [ 'WP:NAV' ],
					'weight' => $score
				],
				'suggest-stop' => [
					'input' => [ 'WP:NAV' ],
					'weight' => $score
				],
			]
		];
		$suggestions = $this->buildSuggestions( $builder, $doc );
		$this->assertSame( $expected, $suggestions );
	}

	public function testDefaultSortAndCrossNS() {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturnCallback( static function ( $ns, $title, $fragment = '', $interwiki = '' ) {
			$ret = Title::makeTitle( $ns, $title, $fragment, $interwiki );
			$ret->resetArticleID( 0 );
			return $ret;
		} );
		$this->setService( 'TitleFactory', $titleFactory );

		$score = 10;
		$crossNsScore = (int)( $score * SuggestBuilder::CROSSNS_DISCOUNT );
		// Test Cross namespace the defaultsort should not be added
		// to cross namespace redirects
		$doc = [
			'id' => 123,
			'title' => 'Guidelines for XYZ',
			'namespace' => NS_HELP,
			'defaultsort' => 'XYZ, Guidelines',
			'redirect' => [
				[ 'title' => "GXYZ", 'namespace' => 0 ],
				[ 'title' => "XYZG", 'namespace' => 0 ],
			],
			'incoming_links' => $score
		];
		$expected = [
			[
				'source_doc_id' => 123,
				'target_title' => [
					'title' => 'Guidelines for XYZ',
					'namespace' => NS_HELP,
				],
				'suggest' => [
					'input' => [ 'GXYZ' ],
					'weight' => $crossNsScore
				],
				'suggest-stop' => [
					'input' => [ 'GXYZ' ],
					'weight' => $crossNsScore
				]
			],
			[
				'source_doc_id' => 123,
				'target_title' => [
					'title' => 'Guidelines for XYZ',
					'namespace' => NS_HELP,
				],
				'suggest' => [
					'input' => [ 'XYZG' ],
					'weight' => $crossNsScore
				],
				'suggest-stop' => [
					'input' => [ 'XYZG' ],
					'weight' => $crossNsScore
				]
			]
		];

		$suggestions = $this->buildSuggestions( $this->buildBuilder(), $doc );
		$this->assertSame( $expected, $suggestions );
	}

	private function buildSuggestions( $builder, $doc ) {
		$id = $doc['id'];
		unset( $doc['id'] );
		$result = [];
		foreach ( $builder->build( [ [ 'id' => $id, 'source' => $doc ] ] ) as $sugg ) {
			$data = $sugg->getData();
			unset( $data['batch_id'] );
			$result[] = $data;
		}
		return $result;
	}

	private function buildBuilder() {
		$extra = [
			new DefaultSortSuggestionsBuilder(),
		];
		return new SuggestBuilder( SuggestScoringMethodFactory::getScoringMethod( 'incomingLinks' ), $extra );
	}
}
