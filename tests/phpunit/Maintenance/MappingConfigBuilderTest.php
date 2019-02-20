<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;

/**
 * @covers \CirrusSearch\Maintenance\MappingConfigBuilder
 */
class MappingConfigBuilderTest extends \MediaWikiTestCase {

	public function buildProvider() {
		$tests = [];
		foreach ( CirrusTestCase::findFixtures( 'mapping/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$buildClass = preg_match( '/\Q-archive.config\E$/', $testFile ) ? ArchiveMappingConfigBuilder::class : MappingConfigBuilder::class;
			$extraConfig = CirrusTestCase::loadFixture( $testFile );
			$expectedFile = dirname( $testFile ) . "/$testName.expected";
			$tests[$testName] = [ $expectedFile, $extraConfig, $buildClass ];
		}
		return $tests;
	}

	/**
	 * @dataProvider buildProvider
	 */
	public function testBuild( $expectedFile, $extraConfig, $buildClass ) {
		// The set of installed extensions might have extra content models
		// which provide extra fields, but we only want the default values
		// from core.
		$this->setMwGlobals( [
			'wgContentHandlers' => $this->defaultContentHandlers(),
		] );
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'CirrusSearchMappingConfig' => [],
			'GetContentModels' => [],
			'SearchIndexFields' => [],
		] );

		$defaultConfig = [
			'CirrusSearchSimilarityProfile' => 'classic',
			'CirrusSearchWikimediaExtraPlugin' => [
				'regex' => [ 'build' ],
			],
			'CirrusSearchPhraseSuggestReverseField' => [
				'build' => true,
			],
		];
		$config = new HashSearchConfig( $defaultConfig + $extraConfig, [ 'inherit' ] );
		$builder = new $buildClass( true, 0, $config );
		$flags = 0;
		$mapping = $builder->buildConfig( $flags );
		$mappingJson = \FormatJson::encode( $mapping, true );

		$createIfMissing = getenv( 'CIRRUS_REBUILD_FIXTURES' ) === 'yes';
		$this->assertFileContains(
			CirrusTestCase::fixturePath( $expectedFile ),
			$mappingJson,
			$createIfMissing
		);
	}

	private function defaultContentHandlers() {
		global $IP;
		require "$IP/includes/DefaultSettings.php";
		return $wgContentHandlers;
	}
}
