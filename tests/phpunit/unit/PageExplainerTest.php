<?php

namespace CirrusSearch;

use CirrusSearch\Test\DummyConnection;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

/**
 * @covers \CirrusSearch\PageExplainer
 * @group CirrusSearch
 */
class PageExplainerTest extends CirrusTestCase {

	private const MAPPINGS = [
		'CirrusSearchNamespaceMappings' => [ NS_MAIN => 'content', NS_CATEGORY => 'general' ],
		'CirrusSearchPrefixIds' => false,
	];

	private function newExplainer( array $config, TitleFactory $titleFactory ): PageExplainer {
		$searchConfig = $this->newHashSearchConfig( $config );
		return new PageExplainer( new DummyConnection( $searchConfig ), $searchConfig, $titleFactory, 'mywiki' );
	}

	private function titleFactoryFor( ?int $namespace ): TitleFactory {
		$title = null;
		if ( $namespace !== null ) {
			$title = $this->createMock( Title::class );
			$title->method( 'getNamespace' )->willReturn( $namespace );
		}
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromID' )->willReturn( $title );
		return $titleFactory;
	}

	/** Routing follows the page's own namespace, not the request's. */
	public static function provideNamespaceRouting(): array {
		return [
			'content namespace' => [ NS_MAIN, 'mywiki_content' ],
			'non-content namespace' => [ NS_CATEGORY, 'mywiki_general' ],
		];
	}

	/** @dataProvider provideNamespaceRouting */
	public function testResolvesIndexFromPageNamespace( int $namespace, string $expectedIndex ) {
		$explainer = $this->newExplainer( self::MAPPINGS, $this->titleFactoryFor( $namespace ) );
		$this->assertSame(
			[ 'index' => $expectedIndex, 'docId' => '123' ],
			$explainer->resolve( 123 )
		);
	}

	/** An unresolvable / deleted page id yields null (the found:false case). */
	public function testUnresolvablePageIdReturnsNull() {
		$explainer = $this->newExplainer( self::MAPPINGS, $this->titleFactoryFor( null ) );
		$this->assertNull( $explainer->resolve( 404 ) );
	}

	/** The docId comes from makeId, so it honours the wiki-id prefix. */
	public function testDocIdHonoursPrefixIds() {
		$config = [
			'CirrusSearchNamespaceMappings' => [ NS_MAIN => 'content' ],
			'CirrusSearchPrefixIds' => true,
			'_wikiID' => 'mywiki',
		];
		$explainer = $this->newExplainer( $config, $this->titleFactoryFor( NS_MAIN ) );
		$this->assertSame(
			[ 'index' => 'mywiki_content', 'docId' => 'mywiki|123' ],
			$explainer->resolve( 123 )
		);
	}
}
