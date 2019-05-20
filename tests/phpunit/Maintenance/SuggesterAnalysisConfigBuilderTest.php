<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\HashSearchConfig;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\Maintenance\SuggesterAnalysisConfigBuilder;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Maintenance\SuggesterAnalysisConfigBuilder
 */
class SuggesterAnalysisConfigBuilderTest extends CirrusTestCase {

	public function provideLanguageAnalysis() {
		$tests = [];
		foreach ( CirrusTestCase::findFixtures( 'languageAnalysisCompSuggest/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$extraConfig = CirrusTestCase::loadFixture( $testFile );
			if ( isset( $extraConfig[ 'LangCode' ] ) ) {
				$langCode = $extraConfig[ 'LangCode' ];
			} else {
				$langCode = $testName;
			}
			$expectedFile = dirname( $testFile ) . "/$testName.expected";
			$tests[ $testName ] = [ $expectedFile, $langCode, $extraConfig ];
		}
		return $tests;
	}

	/**
	 * Test various language specific analysers against fixtures, to make
	 *  the results of generation obvious and tracked in git
	 *
	 * @dataProvider provideLanguageAnalysis
	 * @param mixed $expected
	 * @param string $langCode
	 * @param array $extraConfig
	 */
	public function testLanguageAnalysis( $expected, $langCode, array $extraConfig ) {
		$this->setTemporaryHook( 'CirrusSearchAnalysisConfig',
			function () {
			}
		);
		$config = new HashSearchConfig( $extraConfig + [ 'CirrusSearchSimilarityProfile' => 'default' ] );
		$plugins = [
			'analysis-stempel', 'analysis-kuromoji',
			'analysis-smartcn', 'analysis-hebrew',
			'analysis-ukrainian', 'analysis-stconvert',
			'extra-analysis-serbian', 'extra-analysis-slovak',
			'extra-analysis-esperanto', 'analysis-nori',
		];
		$builder = new SuggesterAnalysisConfigBuilder( $langCode, $plugins, $config );
		if ( !CirrusTestCase::hasFixture( $expected ) ) {
			$createIfMissing = getenv( 'CIRRUS_REBUILD_FIXTURES' ) === 'yes';
			if ( $createIfMissing ) {
				CirrusTestCase::saveFixture( $expected, $builder->buildConfig() );
				$this->markTestSkipped();
				return;
			} else {
				$this->fail( 'Missing fixture file ' . $expected );
			}
		} else {
			$expectedConfig = CirrusTestCase::loadFixture( $expected );
			$this->assertEquals( $expectedConfig, $builder->buildConfig() );
		}
	}
}
