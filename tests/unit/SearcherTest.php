<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;
use Title;

class SearcherTest extends \MediaWikiTestCase {
	public function searchTextProvider() {
		$tests = [];
		foreach ( glob( __DIR__ . '/fixtures/searchText/*.query' ) as $queryFile ) {
			$testName = substr( basename( $queryFile ), 0, -6 );
			$expectedFile = substr( $queryFile, 0, -5 ) . 'expected';
			$tests[$testName] = [
				is_file( $expectedFile )
					? json_decode( file_get_contents( $expectedFile ), true )
					// Flags test to generate a new fixture
					: $expectedFile,
				file_get_contents( $queryFile ),
			];
		}

		return $tests;
	}

	/**
	 * @dataProvider searchTextProvider
	 */
	public function testSearchText( $expected, $queryString ) {
		// Override some config for parsing purposes
		$this->setMwGlobals( [
			'wgCirrusSearchUseExperimentalHighlighter' => true,
			'wgCirrusSearchWikimediaExtraPlugin' => [
				'regex' => [ 'build', 'use' ],
			],
			'wgCirrusSearchQueryStringMaxDeterminizedStates' => 500,
			'wgContentNamespaces' => [ NS_MAIN ],
			// Override the list of namespaces to give more deterministic results
			'wgHooks' => [
				'CanonicalNamespaces' => [
					function ( &$namespaces ) {
						$namespaces = [
							0 => '',
							-2 => 'Media',
							-1 => 'Special',
							1 => 'Talk',
							2 => 'User',
							3 => 'User_talk',
							4 => 'Project',
							5 => 'Project_talk',
							6 => 'File',
							7 => 'File_talk',
							8 => 'MediaWiki',
							9 => 'MediaWiki_talk',
							10 => 'Template',
							11 => 'Template_talk',
							12 => 'Help',
							13 => 'Help_talk',
							14 => 'Category',
							15 => 'Category_talk',
						];
					}
				],
			] + $GLOBALS['wgHooks']
		] );

		// Set a couple pre-defined pages for anything (morelike) that needs valid pages
		$linkCache = MediaWikiServices::getInstance()->getLinkCache();
		$linkCache->addGoodLinkObj( 12345, Title::newFromText( 'Some page' ) );
		$linkCache->addGoodLinkObj( 23456, Title::newFromText( 'Other page' ) );

		\RequestContext::getMain()->setRequest( new \FauxRequest( [
			'cirrusDumpQuery' => 1,
		] ) );

		$engine = new \CirrusSearch();
		// Set some default namespaces, otherwise installed extensions will change
		// the generated query
		$engine->setNamespaces( [
			NS_MAIN, NS_TALK, NS_USER, NS_USER_TALK,
		] );
		$engine->setShowSuggestion( true );
		$engine->setLimitOffset( 20, 0 );
		$engine->setDumpAndDie( false );
		$encodedQuery = $engine->searchText( $queryString );
		if ( is_string( $expected ) ) {
			// Flag to generate a new fixture
			file_put_contents( $expected, $encodedQuery );
		} else {
			$elasticQuery = json_decode( $encodedQuery, true );

			// For extra fun, prefer-recent queries include a 'now' timestamp. We need to normalize that so
			// the output is actually the same.
			$expected = $this->normalizeNow( $expected );
			$elasticQuery = $this->normalizeNow( $elasticQuery );

			// The actual name of the index may vary, and doesn't really matter
			unset( $expected['path'] );
			unset( $elasticQuery['path'] );

			// Finally compare some things
			$this->assertEquals( $expected, $elasticQuery, $encodedQuery );
		}
	}

	private function normalizeNow( array $query ) {
		array_walk_recursive( $query, function ( &$value, $key ) {
			if ( $key === 'now' && is_int( $value ) ) {
				$value = 1468084245000;
			}
		} );

		return $query;
	}
}
