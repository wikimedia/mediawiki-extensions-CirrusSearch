<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;

/**
 * @covers \CirrusSearch\Search\RedirectMode
 * @group CirrusSearch
 */
class RedirectModeTest extends CirrusTestCase {

	/** Every mode, with the answers to the two questions that define it. */
	public static function modeProvider() {
		return [
			// mode, searchesRedirectDocuments, excludesPrimaryDocuments, queriesRedirectArray
			'standard' => [ RedirectMode::Standard, false, false, true ],
			'noredirects' => [ RedirectMode::NoRedirects, false, false, false ],
			'withredirects' => [ RedirectMode::WithRedirects, true, false, false ],
			'onlyredirects' => [ RedirectMode::OnlyRedirects, true, true, false ],
		];
	}

	/** @dataProvider modeProvider */
	public function testPredicates(
		RedirectMode $mode, bool $searchesDocuments, bool $excludesPrimary, bool $queriesArray
	) {
		$this->assertSame( $searchesDocuments, $mode->searchesRedirectDocuments() );
		$this->assertSame( $excludesPrimary, $mode->excludesPrimaryDocuments() );
		$this->assertSame( $queriesArray, $mode->queriesRedirectArray() );
	}

	/**
	 * Only the modes that make redirect documents searchable need the wiki to build them.
	 * noredirects: merely changes which fields are queried, so it works anywhere.
	 * @dataProvider modeProvider
	 */
	public function testRequiresRedirectDocuments( RedirectMode $mode, bool $searchesDocuments ) {
		$this->assertSame( $searchesDocuments, $mode->requiresRedirectDocuments() );
	}

	/**
	 * Fields built from the target's redirect array are allowed only where that array
	 * takes part.
	 * @dataProvider modeProvider
	 */
	public function testAllowsField(
		RedirectMode $mode, bool $searchesDocuments, bool $excludesPrimary, bool $queriesArray
	) {
		foreach ( [ 'redirect.title', 'redirect.title.plain', 'redirect.title.prefix' ] as $field ) {
			$this->assertSame( $queriesArray, $mode->allowsField( $field ), $field );
		}
		foreach ( [ 'title', 'title.plain', 'all', 'all.plain', 'text', 'redirect_target.title' ] as $field ) {
			$this->assertTrue( $mode->allowsField( $field ), $field );
		}
	}

	/** The case values are the keyword strings, so a typed keyword maps straight to a mode. */
	public function testKeywordsMapToModes() {
		$this->assertSame( RedirectMode::WithRedirects, RedirectMode::from( 'withredirects' ) );
		$this->assertSame( RedirectMode::OnlyRedirects, RedirectMode::from( 'onlyredirects' ) );
		$this->assertSame( RedirectMode::NoRedirects, RedirectMode::from( 'noredirects' ) );
	}
}
