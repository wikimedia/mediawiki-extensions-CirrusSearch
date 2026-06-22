<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;
use MediaWiki\Json\FormatJson;
use MediaWiki\Language\Language;
use MediaWiki\MainConfigNames;
use MediaWiki\MainConfigSchema;

/**
 * @covers \CirrusSearch\Maintenance\MappingConfigBuilder
 */
class MappingConfigBuilderTest extends CirrusIntegrationTestCase {

	public static function buildProvider() {
		foreach ( CirrusIntegrationTestCase::findFixtures( 'mapping/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$buildClass = preg_match( '/\Q-archive.config\E$/', $testFile )
				? ArchiveMappingConfigBuilder::class
				: MappingConfigBuilder::class;
			$extraConfig = CirrusIntegrationTestCase::loadFixture( $testFile );
			$expectedFile = dirname( $testFile ) . "/$testName.expected";
			yield $testName => [ $expectedFile, $extraConfig, $buildClass ];
		}
	}

	/**
	 * @dataProvider buildProvider
	 */
	public function testBuild( $expectedFile, $extraConfig, $buildClass ) {
		// The set of installed extensions might have extra content models
		// which provide extra fields, but we only want the default values
		// from core.
		$this->overrideConfigValue(
			MainConfigNames::ContentHandlers,
			MainConfigSchema::ContentHandlers['default']
		);
		$this->clearHooks( [
			'CirrusSearchMappingConfig',
			'GetContentModels',
			'SearchIndexFields',
		] );

		$plugins = $extraConfig[ '__plugins' ] ?? [];
		$defaultConfig = [
			CirrusConfigNames::SimilarityProfile => 'bm25_with_defaults',
			CirrusConfigNames::WikimediaExtraPlugin => [
				'regex' => [ 'build' ],
			],
			CirrusConfigNames::PhraseSuggestReverseField => [
				'build' => true,
			],
			CirrusConfigNames::NaturalTitleSort => [ 'build' => false ],
			CirrusConfigNames::OptimizeIndexForExperimentalHighlighter => false,
		];
		$config = new HashSearchConfig( $extraConfig + $defaultConfig, [ HashSearchConfig::FLAG_INHERIT ] );
		$language = $this->createMock( Language::class );
		$language->method( 'toBcp47Code' )->willReturn( 'my-language-bcp47-code' );
		$builder = new $buildClass( true, $plugins, 0, $config, $this->createCirrusSearchHookRunner(), $language );
		$flags = 0;
		$mapping = $builder->buildConfig( $flags );
		$mappingJson = FormatJson::encode( $mapping, true );

		$this->assertFileContains(
			CirrusIntegrationTestCase::fixturePath( $expectedFile ),
			$mappingJson,
			CirrusIntegrationTestCase::canRebuildFixture()
		);
	}
}
