<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;
use Title;

/**
 * @group CirrusSearch
 */
class SearcherTest extends \MediaWikiTestCase {
	public function searchTextProvider() {
		$configs = [
			'default' => [],
		];
		// globals overrides. All tests will be run for each defined configuration
		foreach ( glob( __DIR__ . '/fixtures/searchText/*.config' ) as $configFile ) {
			$configName = substr( basename( $configFile ), 0, -7 );
			$configs[$configName] = json_decode( file_get_contents( $configFile ), true );
		}
		$tests = [];
		foreach ( glob( __DIR__ . '/fixtures/searchText/*.query' ) as $queryFile ) {
			$testName = substr( basename( $queryFile ), 0, -6 );
			$query = file_get_contents( $queryFile );
			// Remove trailing newline
			$query = preg_replace( '/\n$/', '', $query );
			foreach ( $configs as $configName => $config ) {
				$expectedFile = substr( $queryFile, 0, -5 ) . $configName . '.expected';
				$expected = is_file( $expectedFile )
					? json_decode( file_get_contents( $expectedFile ), true )
					// Flags test to generate a new fixture
					: $expectedFile;
				$tests["{$testName}-{$configName}"] = [
					$config,
					$expected,
					$query,
				];
			}
		}

		return $tests;
	}

	/**
	 * @dataProvider searchTextProvider
	 */
	public function testSearchText( array $config, $expected, $queryString ) {
		// Override some config for parsing purposes
		$this->setMwGlobals( $config + [
			'wgCirrusSearchIndexBaseName' => 'wiki',
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
		$elasticQuery = json_decode( $encodedQuery, true );
		// For extra fun, prefer-recent queries include a 'now' timestamp. We need to normalize that so
		// the output is actually the same.
		$elasticQuery = $this->normalizeNow( $elasticQuery );
		// The helps with ensuring if there are minor code changes that change the ordering,
		// regenerating the fixture wont cause changes. Do it always, instead of only when
		// writing, so that the diff's from phpunit are also as minimal as possible.
		$elasticQuery = $this->normalizeOrdering( $elasticQuery);
		// The actual name of the index may vary, and doesn't really matter
		unset( $elasticQuery['path'] );

		if ( is_string( $expected ) ) {
			// Flag to generate a new fixture.
			$encodedQuery = json_encode( $elasticQuery, JSON_PRETTY_PRINT );
			file_put_contents( $expected, $encodedQuery );
		} else {
			// Repeat normalizations applied to $elasticQuery
			$expected = $this->normalizeNow( $expected );
			unset( $expected['path'] );

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

	private function normalizeOrdering( array $query ) {
		foreach ( $query as $key => $value ) {
			if ( is_array( $value ) ) {
				$query[$key] = $this->normalizeOrdering( $value );
			}
		}
		if ( isset( $query[0] ) ) {
			// list like. Expensive, but sorta-works?
			usort( $query, function ( $a, $b ) {
				return strcmp( json_encode( $a ), json_encode( $b ) );
			} );
		} else {
			// dict like
			ksort( $query );
		}

		return $query;
	}
}
