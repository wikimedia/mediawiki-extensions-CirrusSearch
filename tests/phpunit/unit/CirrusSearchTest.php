<?php

namespace CirrusSearch;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Status\Status;

/**
 * @group CirrusSearch
 */
class CirrusSearchTest extends CirrusTestCase {

	public static function provideProfiles() {
		return [
			'completion' => [
				\SearchEngine::COMPLETION_PROFILE_TYPE,
				CirrusSearch::AUTOSELECT_PROFILE,
				[ CirrusSearch::AUTOSELECT_PROFILE, CirrusSearch::COMPLETION_PREFIX_FALLBACK_PROFILE ],
			],
			'fulltext query independent' => [
				\SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE,
				CirrusSearch::AUTOSELECT_PROFILE,
				[ CirrusSearch::AUTOSELECT_PROFILE, 'classic' ],
			],
			'unknown' => [
				'unknown',
				null,
				[],
			],
		];
	}

	/**
	 * @dataProvider provideProfiles
	 * @covers       \CirrusSearch\CirrusSearch::getProfiles()
	 */
	public function testGetProfiles( $profileType, $default, array $expectedProfiles ) {
		$profiles = $this->getSearchEngine( [ 'CirrusSearchUseCompletionSuggester' => 'yes' ] )
			->getProfiles( $profileType );
		if ( $default === null ) {
			$this->assertNull( $profiles );
		} else {
			$this->assertIsArray( $profiles );
			$nameMap = [];
			foreach ( $profiles as $p ) {
				$this->assertIsArray( $p );
				$this->assertArrayHasKey( 'name', $p );
				$nameMap[$p['name']] = $p;
			}
			foreach ( $expectedProfiles as $expectedProfile ) {
				$this->assertArrayHasKey( $expectedProfile, $nameMap );
				$this->assertArrayHasKey( 'desc-message', $nameMap[$expectedProfile] );
			}
			$this->assertArrayHasKey( 'default', $nameMap[$default] );
		}
	}

	public static function provideExtractProfileFromFeatureData() {
		return [
			'engine defaults (completion)' => [
				\SearchEngine::COMPLETION_PROFILE_TYPE,
				CirrusSearch::AUTOSELECT_PROFILE,
				null,
			],
			'engine defaults (fulltext qi)' => [
				\SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE,
				CirrusSearch::AUTOSELECT_PROFILE,
				null,
			],
			'profile set (completion)' => [
				\SearchEngine::COMPLETION_PROFILE_TYPE,
				'foobar',
				'foobar',
			],
			'profile set (fulltext qi)' => [
				\SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE,
				'foobar',
				'foobar',
			]
		];
	}

	/**
	 * @dataProvider provideExtractProfileFromFeatureData
	 * @covers       \CirrusSearch\CirrusSearch::extractProfileFromFeatureData
	 * @throws \MediaWiki\Config\ConfigException
	 */
	public function testExtractProfileFromFeatureData( $type, $setValue, $expected ) {
		$engine = $this->getSearchEngine( [ 'CirrusSearchUseCompletionSuggester' => 'yes' ] );
		$engine->setFeatureData( $type, $setValue );
		$this->assertEquals( $expected, $engine->extractProfileFromFeatureData( $type ) );
	}

	public static function provideCompletionSuggesterEnabled() {
		return [
			'enabled' => [
				'yes', true
			],
			'enabled with bool' => [
				true, true
			],
			'disabled' => [
				'no', false
			],
			'disabled with bool' => [
				false, false
			],
			'disabled with random' => [
				'foo', false
			],
		];
	}

	/**
	 * @covers \CirrusSearch\CirrusSearch::doSearchText
	 */
	public function testFailureOnQueryLength() {
		$engine = $this->getSearchEngine( [ 'CirrusSearchMaxFullTextQueryLength' => 10 ] );
		$engine->setHookContainer( $this->createMock( HookContainer::class ) );
		$status = $engine->searchText( str_repeat( "a", 11 ) );
		$this->assertEquals( $status,
			Status::newFatal( 'cirrussearch-query-too-long', 11, 10 ) );
	}

	/**
	 * @param array|null $config
	 * @return CirrusSearch
	 * @throws \MediaWiki\Config\ConfigException
	 */
	private function getSearchEngine( ?array $config = null ) {
		// use cirrus base profiles
		// only set needed config for Connection
		$config = $this->newHashSearchConfig( ( $config ?: [] ) + $this->getMinimalConfig() );
		return new CirrusSearch( $config, CirrusDebugOptions::defaultOptions(),
			$this->namespacePrefixParser(), $this->getInterWikiResolver( $config ), $this->newTitleHelper() );
	}

	/**
	 * @return array
	 */
	private function getMinimalConfig() {
		return [
			'CirrusSearchClusters' => [
				'default' => [ 'localhost' ],
			],
			'CirrusSearchDefaultCluster' => 'default',
			'CirrusSearchReplicaGroup' => 'default',
		];
	}

	/**
	 * @covers \CirrusSearch\CirrusSearch::supports
	 */
	public function testSupports() {
		$engine = $this->getSearchEngine();
		$this->assertFalse( $engine->supports( 'search-update' ) );
		$this->assertFalse( $engine->supports( 'list-redirects' ) );
		$this->assertTrue( $engine->supports( \SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE ) );
		$this->assertTrue( $engine->supports( CirrusSearch::EXTRA_FIELDS_TO_EXTRACT ) );
	}
}
