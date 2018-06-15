<?php

namespace CirrusSearch;

/**
 * @group CirrusSearch
 */
class CirrusSearchTest extends CirrusTestCase {

	public function provideProfiles() {
		return [
			'completion' => [
				\SearchEngine::COMPLETION_PROFILE_TYPE,
				\CirrusSearch::AUTOSELECT_PROFILE,
				[ \CirrusSearch::AUTOSELECT_PROFILE, \CirrusSearch::COMPLETION_PREFIX_FALLBACK_PROFILE ],
			],
			'fulltext query independent' => [
				\SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE,
				\CirrusSearch::AUTOSELECT_PROFILE,
				[ \CirrusSearch::AUTOSELECT_PROFILE, 'classic' ],
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
	 * @covers \CirrusSearch::getProfiles()
	 */
	public function testGetProfiles( $profileType, $default, array $expectedProfiles ) {
		$profiles = $this->getMinimalSearchEngine()->getProfiles( $profileType );
		if ( $default === null ) {
			$this->assertNull( $profiles );
		} else {
			$this->assertType( 'array', $profiles );
			$nameMap = [];
			foreach ( $profiles as $p ) {
				$this->assertType( 'array', $p );
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

	public function provideExtractProfileFromFeatureData() {
		return [
			'engine defaults (completion)' => [
				\SearchEngine::COMPLETION_PROFILE_TYPE,
				\CirrusSearch::AUTOSELECT_PROFILE,
				null,
			],
			'engine defaults (fulltext qi)' => [
				\SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE,
				\CirrusSearch::AUTOSELECT_PROFILE,
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
	 * @covers CirrusSearch::extractProfileFromFeatureData()
	 * @throws \ConfigException
	 */
	public function testExtractProfileFromFeatureData( $type, $setValue, $expected ) {
		$engine = $this->getMinimalSearchEngine();
		$engine->setFeatureData( $type, $setValue );
		$this->assertEquals( $expected, $engine->extractProfileFromFeatureData( $type ) );
	}

	/**
	 * @return \CirrusSearch
	 * @throws \ConfigException
	 */
	private function getMinimalSearchEngine() {
		// use cirrus base profiles
		// only set needed config for Connection
		$config = [
			'CirrusSearchClusters' => [
				'default' => [ 'localhost' ],
			],
			'CirrusSearchDefaultCluster' => 'default',
		];
		return new \CirrusSearch( null, new HashSearchConfig( $config ) );
	}
}
