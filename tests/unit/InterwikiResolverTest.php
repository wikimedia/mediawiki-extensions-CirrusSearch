<?php

namespace CirrusSearch\Test;

use MediaWiki\MediaWikiServices;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\CirrusConfigInterwikiResolver;
use CirrusSearch\SiteMatrixInterwikiResolver;
use CirrusSearch\InterwikiResolverFactory;
use CirrusSearch\InterwikiResolver;

/**
 * @group CirrusSearch
 */
class InterwikiResolverTest extends CirrusTestCase {
	public function testCirrusConfigInterwikiResolver() {
		$resolver = $this->getCirrusConfigInterwikiResolver();

		// Test wikiId => prefix map
		$this->assertEquals( 'fr', $resolver->getInterwikiPrefix( 'frwiki' ) );
		$this->assertEquals( 'no', $resolver->getInterwikiPrefix( 'nowiki' ) );
		$this->assertEquals( 'b', $resolver->getInterwikiPrefix( 'enwikibooks' ) );
		$this->assertEquals( null, $resolver->getInterwikiPrefix( 'simplewiki' ) );
		$this->assertEquals( null, $resolver->getInterwikiPrefix( 'enwiki' ) );

		// Test sister projects
		$this->assertArrayHasKey( 'voy', $resolver->getSisterProjectPrefixes() );
		$this->assertArrayHasKey( 'b', $resolver->getSisterProjectPrefixes() );
		$this->assertEquals( 'enwikivoyage', $resolver->getSisterProjectPrefixes()['voy'] );
		$this->assertArrayNotHasKey( 'commons', $resolver->getSisterProjectPrefixes() );

		// Test by-language lookup
		$this->assertEquals(
			[ 'frwiki', 'fr' ],
			$resolver->getSameProjectWikiByLang( 'fr' )
		);
		$this->assertEquals(
			[ 'nowiki', 'no' ],
			$resolver->getSameProjectWikiByLang( 'no' )
		);
		$this->assertEquals(
			[ 'nowiki', 'no' ],
			$resolver->getSameProjectWikiByLang( 'nb' )
		);
		$this->assertEquals(
			[],
			$resolver->getSameProjectWikiByLang( 'ccc' )
		);
		$this->assertEquals(
			[],
			$resolver->getSameProjectWikiByLang( 'en' ),
			'enwiki should not find itself.'
		);

	}

	/**
	 * @dataProvider provideSiteMatrixTestCases
	 * @param string $wiki string
	 * @param string $what method to test
	 * @param mixed $arg arg to $what
	 * @param mixed $expected expected result of $what($arg)
	 */
	public function testSiteMatrixResolver( $wiki, $what, $arg, $expected, $blacklist = [] ) {
		if ( !class_exists( \SiteMatrix::class ) ) {
			$this->markTestSkipped( 'SiteMatrix not available.' );
		}

		$resolver = $this->getSiteMatrixInterwikiResolver( $wiki, $blacklist );
		switch( $what ) {
		case 'sisters':
			asort( $expected );
			$actual = $resolver->getSisterProjectPrefixes();
			asort( $actual );

			$this->assertEquals(
				$expected,
				$actual
			);
			break;
		case 'interwiki':
			$this->assertEquals(
				$expected,
				$resolver->getInterwikiPrefix( $arg )
			);
			break;
		case 'crosslang':
			$this->assertEquals(
				$expected,
				$resolver->getSameProjectWikiByLang( $arg )
			);
			break;
		default: throw new \Exception( "Invalid op $what" );
		}
	}

	public static function provideSiteMatrixTestCases() {
		return [
			'enwiki sisters' => [
				'enwiki',
				'sisters', null,
				[
					'wikt' => 'enwiktionary',
					'b' => 'enwikibooks',
					'n' => 'enwikinews',
					'q' => 'enwikiquote',
					's' => 'enwikisource',
					'v' => 'enwikiversity',
					'voy' => 'enwikivoyage'
				]
			],
			'enwiki sisters with blacklist' => [
				'enwiki',
				'sisters', null,
				[
					'wikt' => 'enwiktionary',
					's' => 'enwikisource',
					'voy' => 'enwikivoyage'
				],
				[ 'n', 'b', 'q', 'v' ]

			],
			'enwikibook sisters' => [
				'enwikibooks',
				'sisters', null,
				[
					'wikt' => 'enwiktionary',
					'w' => 'enwiki',
					'n' => 'enwikinews',
					'q' => 'enwikiquote',
					's' => 'enwikisource',
					'v' => 'enwikiversity',
					'voy' => 'enwikivoyage'
				]
			],
			'mywiki sisters load only open projects' => [
				'mywiki',
				'sisters', null,
				[
					'wikt' => 'mywiktionary'
				],
			],
			'enwiki interwiki can find sister projects project enwikibooks' => [
				'enwiki',
				'interwiki', 'enwikibooks',
				'b'
			],
			'enwiki interwiki can find same project other lang: frwiki' => [
				'enwiki',
				'interwiki', 'frwiki',
				'fr'
			],
			'enwiki interwiki cannot find other project other lang: frwiktionary' => [
				'enwiki',
				'interwiki', 'frwiktionary',
				null
			],
			'enwiki interwiki cannot find itself' => [
				'enwiki',
				'interwiki', 'enwiki',
				null
			],
			'enwiki interwiki can find project with non default lang: nowiki' => [
				'enwiki',
				'interwiki', 'nowiki',
				'no'
			],
			'enwiki interwiki ignores closed projects: mowiki' => [
				'enwiki',
				'interwiki', 'mowiki',
				null
			],
			'enwiki interwiki ignores projects not directly with lang/project: officewiki' => [
				'enwiki',
				'interwiki', 'officewiki',
				null
			],
			'frwikinews interwiki ignore inexistent projects: mywikinews' => [
				'frwikinews',
				'interwiki', 'mywikinews',
				null
			],
			'enwiki cross lang lookup finds frwiki' => [
				'enwiki',
				'crosslang', 'fr',
				['frwiki', 'fr'],
			],
			'enwiki cross lang lookup finds nowiki' => [
				'enwiki',
				'crosslang', 'nb',
				['nowiki', 'no'],
			],
			'enwikinews cross lang lookup finds frwikinews' => [
				'enwikinews',
				'crosslang', 'fr',
				['frwikinews', 'fr'],
			],
			'enwikinews cross lang lookup cannot find inexistent hawwikinews' => [
				'enwikinews',
				'crosslang', 'haw',
				[],
			],
			'enwikinews cross lang lookup cannot find closed nlwikinews' => [
				'enwikinews',
				'crosslang', 'nl',
				[],
			],
			'enwikinews cross lang lookup should not find itself' => [
				'enwikinews',
				'crosslang', 'en',
				[],
			],
		];
	}

	private function getCirrusConfigInterwikiResolver() {
		$wikiId = 'enwiki';
		$myGlobals = [
			'wgDBprefix' => null,
			'wgDBName' => $wikiId,
			'wgLanguageCode' => 'en',
			'wgCirrusSearchInterwikiSources' => [
				'voy' => 'enwikivoyage',
				'wikt' => 'enwiktionary',
				'b' => 'enwikibooks',
			],
			'wgCirrusSearchLanguageToWikiMap' => [
				'fr' => 'fr',
				'nb' => 'no',
				'en' => 'en',
			],
			'wgCirrusSearchWikiToNameMap' => [
				'fr' => 'frwiki',
				'no' => 'nowiki',
				'en' => 'enwiki',
			]
		];
		$this->setMwGlobals( $myGlobals );
		$myGlobals['_wikiID'] = $wikiId;
		// We need to reset this service so it can load wgInterwikiCache
		$config = new HashSearchConfig( $myGlobals, ['inherit'] );
		$resolver = MediaWikiServices::getInstance()
			->getService( InterwikiResolverFactory::SERVICE )
			->getResolver( $config );
		$this->assertEquals( CirrusConfigInterwikiResolver::class, get_class( $resolver ) );
		return $resolver;
	}

	private function getSiteMatrixInterwikiResolver( $wikiId, array $blacklist ) {
		$conf = new \SiteConfiguration;
		$conf->settings = include( __DIR__ . '/resources/wmf/SiteMatrix_SiteConf_IS.php' );
		$conf->suffixes = include( __DIR__ . '/resources/wmf/suffixes.php' );
		$conf->wikis = self::readDbListFile( __DIR__ . '/resources/wmf/all.dblist' );

		$myGlobals = [
			'wgConf' => $conf,
			// Used directly by SiteMatrix
			'wgLocalDatabases' => $conf->wikis,
			// Used directly by SiteMatrix & SiteMatrixInterwikiResolver
			'wgSiteMatrixSites' => include( __DIR__ . '/resources/wmf/SiteMatrixProjects.php' ),
			// Used by SiteMatrix
			'wgSiteMatrixFile' => __DIR__ . '/resources/wmf/langlist',
			// Used by SiteMatrix
			'wgSiteMatrixClosedSites' => self::readDbListFile( __DIR__ . '/resources/wmf/closed.dblist' ),
			// Used by SiteMatrix
			'wgSiteMatrixPrivateSites' => self::readDbListFile( __DIR__ . '/resources/wmf/private.dblist' ),
			// Used by SiteMatrix
			'wgSiteMatrixFishbowlSites' => self::readDbListFile( __DIR__ . '/resources/wmf/fishbowl.dblist' ),

			// XXX: for the purpose of the test we need
			// to have wfWikiID() without DBPrefix so we can reuse
			// the wmf InterwikiCache which is built against WMF config
			// where no wgDBprefix is set.
			'wgDBprefix' => null,
			'wgDBname' => $wikiId,
			// Used by ClassicInterwikiLookup & SiteMatrixInterwikiResolver
			'wgInterwikiCache' => include( __DIR__ . '/resources/wmf/interwiki.php' ),
			// Reset values so that SiteMatrixInterwikiResolver is used
			'wgCirrusSearchInterwikiSources' => [],
			'wgCirrusSearchLanguageToWikiMap' => [],
			'wgCirrusSearchWikiToNameMap' => [],
			'wgCirrusSearchCrossProjectSearchBlackList' => $blacklist,
		];
		$this->setMwGlobals( $myGlobals );
		$myGlobals['_wikiID'] = $wikiId;
		// We need to reset this service so it can load wgInterwikiCache
		MediaWikiServices::getInstance()
			->resetServiceForTesting( 'InterwikiLookup' );
		$config = new HashSearchConfig( $myGlobals, ['inherit'] );
		$resolver = MediaWikiServices::getInstance()
			->getService( InterwikiResolverFactory::SERVICE )
			->getResolver( $config );
		$this->assertEquals( SiteMatrixInterwikiResolver::class, get_class( $resolver ) );
		return $resolver;
	}

	protected function tearDown() {
		MediaWikiServices::getInstance()
			->resetServiceForTesting( 'InterwikiLookup' );
		parent::tearDown();
	}

	private static function readDbListFile( $fileName ) {
		\MediaWiki\suppressWarnings();
		$fileContent = file( $fileName, FILE_IGNORE_NEW_LINES );
		\MediaWiki\restoreWarnings();
		return $fileContent;
	}
}
