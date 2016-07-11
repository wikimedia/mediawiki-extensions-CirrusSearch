<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;

class SearcherTest extends \MediaWikiTestCase {
	public function searchTextProvider() {
		$tests = array();
		foreach ( glob( __DIR__ . '/fixtures/searchText/*.query' ) as $queryFile ) {
			$testName = substr( basename( $queryFile ), 0, -6 );
			$expectedFile = substr( $queryFile, 0, -5 ) . 'expected';
			$tests[$testName] = array(
				is_file( $expectedFile )
					? json_decode( file_get_contents( $expectedFile ), true )
					// Flags test to generate a new fixture
					: $expectedFile,
				file_get_contents( $queryFile ),
			);
		}

		return $tests;
	}

	/**
	 * @dataProvider searchTextProvider
	 */
	public function testSearchText( $expected, $queryString ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
		// Use real connection for simplicity, but no network request will be sent.
		$conn = Connection::getPool( $config );

		// Override some config for parsing purposes
		$this->setMwGlobals( array(
			'wgCirrusSearchUseExperimentalHighlighter' => true,
			'wgCirrusSearchWikimediaExtraPlugin' => array(
				'regex' => array( 'build', 'use' ),
			),
			'wgCirrusSearchQueryStringMaxDeterminizedStates' => 500,
			'wgContentNamespaces' => array( NS_MAIN ),
		) );

		// Override the list of namespaces to give more deterministic results
		$this->setMwGlobals( array(
			'wgHooks' => array(
				'CanonicalNamespaces' => array(
					function ( &$namespaces ) {
						$namespaces = array(
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
						);
					}
				),
			) + $GLOBALS['wgHooks']
		) );


		// Set some default namespaces, otherwise installed extensions will change
		// the generated query
		$searcher = new Searcher( $conn, 0, 20, $config, array(
			NS_MAIN, NS_TALK, NS_USER, NS_USER_TALK,
		) );
		$searcher->setReturnQuery( true );
		$result = $searcher->searchText( $queryString, true );
		$this->assertTrue( $result->isOK() );
		$elasticQuery = $result->getValue();
		if ( is_string( $expected ) ) {
			// Flag to generate a new fixture
			file_put_contents( $expected, json_encode( $elasticQuery, JSON_PRETTY_PRINT ) );
		} else {
			// To make debugging easier we want to compare the decoded arrays, rather than the encoded
			// json elasticsearch recieves. Unfortunately the empty objects ({}) in the output are not
			// round-tripable by php json parsers into the source elastica generates. As such round trip
			// the result once to make it equivilent.
			$encoded = json_encode( $elasticQuery, JSON_PRETTY_PRINT );
			$elasticQuery = json_decode( $encoded, true );

			// For extra fun, prefer-recent queries include a 'now' timestamp. We need to normalize that so
			// the output is actually the same.
			$expected = $this->normalizeNow( $expected );
			$elasticQuery = $this->normalizeNow( $elasticQuery );

			// The actual name of the index may vary, and doesn't really matter
			unset( $expected['path'] );
			unset( $elasticQuery['path'] );

			// Finally compare some things
			$this->assertEquals( $expected, $elasticQuery, $encoded );
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
