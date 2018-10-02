<?php

namespace CirrusSearch;

use CirrusSearch\Profile\SearchProfileService;
use MediaWiki\MediaWikiServices;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\SearchConfig
 * @covers \CirrusSearch\HashSearchConfig
 */
class SearchConfigTest extends CirrusTestCase {

	public function testGetters() {
		$config = new HashSearchConfig( [
			'test' => 1,
			'one' => [ 'two' => 3 ]
		] );
		$this->assertEquals( wfWikiID(), $config->getWikiId() );
		$this->assertEquals( 1, $config->get( 'test' ) );
		$this->assertTrue( $config->has( 'test' ) );
		$this->assertNull( $config->get( 'unknown' ) );
		$this->assertFalse( $config->has( 'unknown' ) );
		$this->assertEquals( [ 'two' => 3 ], $config->getElement( 'one' ) );
		$this->assertEquals( 3, $config->getElement( 'one', 'two' ) );
		$this->assertEquals( wfWikiID(), $config->getWikiId() );
	}

	public function testMakeId() {
		$config = new HashSearchConfig( [
			'CirrusSearchPrefixIds' => true,
			'_wikiID' => 'mywiki',
		] );

		$this->assertEquals( 'mywiki|123', $config->makeId( 123 ) );
		$this->assertEquals( 123, $config->makePageId( 'mywiki|123' ) );
		$this->assertEquals( 123, $config->makePageId( '123' ) );
		try {
			$this->assertEquals( 123, $config->makePageId( 'mywiki|hop|123' ) );
			$this->fail();
		} catch ( \Exception $e ) {
			$this->assertEquals( $e->getMessage(), "Invalid document id: mywiki|hop|123" );
		}

		$config = new HashSearchConfig( [
			'CirrusSearchPrefixIds' => false,
			'_wikiID' => 'mywiki',
		] );

		$this->assertEquals( '123', $config->makeId( 123 ) );
		$this->assertEquals( 123, $config->makePageId( '123' ) );
		// should this fail instead?
		$this->assertEquals( 0, $config->makePageId( 'mywiki|123' ) );
	}

	public function testInherit() {
		$this->setMwGlobals( [
			'wgTestVar' => 'test'
		] );
		$config = new HashSearchConfig( [ 'foo' => 'bar' ], [ 'inherit' ] );
		$this->assertEquals( 'bar', $config->get( 'foo' ) );
		$this->assertTrue( $config->has( 'TestVar' ) );
		$config = new HashSearchConfig( [ 'foo' => 'bar' ] );
		$this->assertEquals( 'bar', $config->get( 'foo' ) );
		$this->assertFalse( $config->has( 'TestVar' ) );
	}

	public function testWritableClusters() {
		$this->setMwGlobals( [
			'wgCirrusSearchClusters' => [
				'one' => [],
				'two' => [],
				'readonly' => [],
			],
			'wgCirrusSearchWriteClusters' => [ 'one', 'two', 'unknown' ]
		] );
		$config = new SearchConfig();
		// Unclear if it's right to not filter out with available cluster
		// ElasticaWrite should error out if the cluster is unknown tho.
		$this->assertEquals( [ 'one', 'two', 'unknown' ], $config->getWritableClusters() );
		$this->assertTrue( $config->canWriteToCluster( 'one' ) );
		$this->assertTrue( $config->canWriteToCluster( 'unknown' ) );
		$this->assertFalse( $config->canWriteToCluster( 'readonly' ) );
	}

	public function testCrossSearchAccessors() {
		$config = new SearchConfig();
		$this->assertFalse( $config->isCrossLanguageSearchEnabled() );
		$this->assertFalse( $config->isCrossProjectSearchEnabled() );
		$this->setMwGlobals( [
			'wgCirrusSearchEnableCrossProjectSearch' => true,
			'wgCirrusSearchEnableAltLanguage' => true,
		] );
		$config = new SearchConfig();
		$this->assertTrue( $config->isCrossLanguageSearchEnabled() );
		$this->assertTrue( $config->isCrossProjectSearchEnabled() );
	}

	public function testMWServiceIntegration() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		$this->assertInstanceOf( SearchConfig::class, $config );
	}

	public function testLoadContLang() {
		global $wgContLang;
		$config = new HashSearchConfig( [ 'LanguageCode' => 'fr' ], [ 'load-cont-lang', 'inherit' ] );
		$frContLang = $config->get( 'ContLang' );
		$this->assertNotSame( $wgContLang, $frContLang );
		$this->assertSame( \Language::factory( 'fr' ), $frContLang );
	}

	public function testLocalWiki() {
		$this->assertTrue( ( new SearchConfig() )->isLocalWiki() );
		$this->assertFalse( ( new HashSearchConfig( [] ) )->isLocalWiki() );
	}

	public function testWikiIDOverride() {
		$config = new HashSearchConfig( [] );
		$this->assertEquals( wfWikiID(), $config->getWikiId() );
		$config = new HashSearchConfig( [ '_wikiID' => 'myverycustomwiki' ] );
		$this->assertEquals( 'myverycustomwiki', $config->getWikiId() );
	}

	public function testProfileService() {
		$this->setMwGlobals( [
			'wgCirrusSearchRescoreProfiles' => [
				'bar' => []
			]
		] );
		$config = new HashSearchConfig( [ 'CirrusSearchRescoreProfiles' => [ 'foo' => [] ] ] );
		$service = $config->getProfileService();
		$this->assertSame( $service, $config->getProfileService() );

		$this->assertNotNull( $service->loadProfileByName( SearchProfileService::COMPLETION,
			\CirrusSearch::COMPLETION_PREFIX_FALLBACK_PROFILE, false ) );
		$this->assertNotNull( $service->loadProfileByName( SearchProfileService::RESCORE,
			'foo', false ) );
		$this->assertNull( $service->loadProfileByName( SearchProfileService::RESCORE,
			'bar', false ) );
	}

	public function testIndexBaseName() {
		$config = new SearchConfig();
		$this->assertEquals( wfWikiID(), $config->get( 'CirrusSearchIndexBaseName' ) );
		$config = new HashSearchConfig( [ 'CirrusSearchIndexBaseName' => 'foobar' ] );
		$this->assertEquals( 'foobar', $config->get( 'CirrusSearchIndexBaseName' ) );
	}
}
