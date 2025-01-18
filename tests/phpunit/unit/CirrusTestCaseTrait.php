<?php

namespace CirrusSearch;

use CirrusSearch\Parser\NamespacePrefixParser;
use CirrusSearch\Parser\QueryParserFactory;
use CirrusSearch\Profile\PhraseSuggesterProfileRepoWrapper;
use CirrusSearch\Profile\SearchProfileServiceFactory;
use CirrusSearch\Profile\SearchProfileServiceFactoryFactory;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Search\TitleHelper;
use MediaWiki\Config\Config;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\StaticUserOptionsLookup;
use MediaWiki\User\Options\UserOptionsLookup;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

trait CirrusTestCaseTrait {
	/** @var string */
	public static $FIXTURE_DIR = __DIR__ . '/../fixtures/';
	/** @var string */
	public static $CIRRUS_REBUILD_FIXTURES = 'CIRRUS_REBUILD_FIXTURES';

	/**
	 * @var int|null (lazy loaded)
	 */
	private static $SEED;

	/**
	 * @var int
	 */
	private static $MAX_TESTED_FIXTURES_PER_TEST;

	/**
	 * @return bool
	 */
	public static function canRebuildFixture() {
		return getenv( self::$CIRRUS_REBUILD_FIXTURES ) === 'yes';
	}

	/**
	 * @return int
	 */
	public static function getSeed() {
		if ( self::$SEED === null ) {
			if ( is_numeric( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_SEED' ) ) ) {
				self::$SEED = intval( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_SEED' ) );
			} else {
				self::$SEED = time();
			}
		}
		return self::$SEED;
	}

	/**
	 * @return int
	 */
	public static function getMaxTestedFixturesPerTest() {
		if ( self::$MAX_TESTED_FIXTURES_PER_TEST === null ) {
			if ( is_numeric( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_MAX_FIXTURES_PER_TEST' ) ) ) {
				self::$MAX_TESTED_FIXTURES_PER_TEST = intval( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_MAX_FIXTURES_PER_TEST' ) );
			} else {
				self::$MAX_TESTED_FIXTURES_PER_TEST = 200;
			}
		}
		return self::$MAX_TESTED_FIXTURES_PER_TEST;
	}

	/**
	 * @param string $path
	 * @return string[]
	 */
	public static function findFixtures( $path ) {
		$prefixLen = strlen( self::$FIXTURE_DIR );
		$results = [];
		foreach ( glob( self::$FIXTURE_DIR . $path ) as $file ) {
			$results[] = substr( $file, $prefixLen );
		}
		return $results;
	}

	/**
	 * @param string $testFile
	 * @param mixed $fixture
	 */
	public static function saveFixture( $testFile, $fixture ) {
		file_put_contents(
			self::$FIXTURE_DIR . $testFile,
			self::encodeFixture( $fixture )
		);
	}

	/**
	 * @param string $testFile
	 * @param mixed $fixture
	 */
	public static function saveAnalysisFixture( $testFile, $fixture ) {
		// sort top level and second level of analysis fixtures
		// and third level for "analyzer"
		ksort( $fixture );
		foreach ( $fixture as $key => &$value ) {
			if ( is_array( $value ) ) {
				ksort( $value );
			}
			if ( $key == 'analyzer' ) {
				foreach ( $fixture[ $key ] as &$analyzer ) {
					if ( is_array( $analyzer ) ) {
						ksort( $analyzer );
					}
				}
			}
		}
		self::saveFixture( $testFile, $fixture );
	}

	/**
	 * @param mixed $fixture
	 * @return string
	 */
	public static function encodeFixture( $fixture ) {
		$old = ini_set( 'serialize_precision', 14 );
		try {
			return json_encode( $fixture, JSON_PRETTY_PRINT );
		} finally {
			ini_set( 'serialize_precision', $old );
		}
	}

	/**
	 * @param array $cases
	 * @return array
	 */
	public static function randomizeFixtures( array $cases ): array {
		if ( self::canRebuildFixture() ) {
			return $cases;
		}
		ksort( $cases );
		srand( self::getSeed() );
		$randomizedKeys = array_rand( $cases, min( count( $cases ), self::getMaxTestedFixturesPerTest() ) );
		$randomizedCases = array_intersect_key( $cases, array_flip( $randomizedKeys ) );
		return $randomizedCases;
	}

	public static function hasFixture( string $testFile ): bool {
		return is_file( self::$FIXTURE_DIR . $testFile );
	}

	public static function loadTextFixture( string $testFile, string $errorMessage = "fixture config" ): string {
		return file_get_contents( self::$FIXTURE_DIR . $testFile );
	}

	public static function loadFixture( string $testFile, string $errorMessage = "fixture config" ): array {
		$decoded = json_decode( self::loadTextFixture( $testFile ), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( "Failed decoding {$errorMessage}: $testFile" );
		}
		return $decoded;
	}

	public static function fixturePath( string $testFile ): string {
		return self::$FIXTURE_DIR . $testFile;
	}

	/**
	 * Capture the args of a mocked method
	 *
	 * @param mixed &$args placeholder for args to capture
	 * @param callable|null $callback optional callback methods to run on captured args
	 * @return \PHPUnit\Framework\Constraint\Callback
	 * @see Assert::callback()
	 */
	public function captureArgs( &$args, ?callable $callback = null ) {
		return $this->callback( static function ( ...$argToCapture ) use ( &$args, $callback ) {
			$args = $argToCapture;
			if ( $callback !== null ) {
				return $callback( $argToCapture );
			}
			return true;
		} );
	}

	/**
	 * @param \Elastica\Response ...$responses
	 * @return \Elastica\Transport\AbstractTransport
	 */
	public function mockTransportWithResponse( ...$responses ) {
		$transport = $this->createMock( \Elastica\Transport\AbstractTransport::class );
		$transport->method( 'exec' )
			->willReturnOnConsecutiveCalls( ...$responses );
		return $transport;
	}

	/**
	 * @param array $config
	 * @param array $flags
	 * @param Config|null $inherited
	 * @param SearchProfileServiceFactoryFactory|null $factoryFactory
	 * @return SearchConfig
	 */
	public function newHashSearchConfig(
		array $config = [],
		$flags = [],
		?Config $inherited = null,
		?SearchProfileServiceFactoryFactory $factoryFactory = null
	): SearchConfig {
		return new HashSearchConfig( $config, $flags, $inherited,
			$factoryFactory ?: $this->hostWikiSearchProfileServiceFactory() );
	}

	/**
	 * @param CirrusSearchHookRunner|null $cirrusSearchHookRunner
	 * @param UserOptionsLookup|null $userOptionsLookup
	 * @return SearchProfileServiceFactoryFactory
	 */
	public function hostWikiSearchProfileServiceFactory(
		?CirrusSearchHookRunner $cirrusSearchHookRunner = null,
		?UserOptionsLookup $userOptionsLookup = null
	): SearchProfileServiceFactoryFactory {
		$cirrusSearchHookRunner = $cirrusSearchHookRunner ?: $this->createCirrusSearchHookRunner( [] );
		$userOptionsLookup = $userOptionsLookup ?: $this->createStaticUserOptionsLookup();
		return new class(
			$this,
			$cirrusSearchHookRunner,
			$userOptionsLookup
		) implements SearchProfileServiceFactoryFactory {
			/** @var CirrusTestCaseTrait */
			private $testCase;
			/** @var CirrusSearchHookRunner */
			private $cirrusHookRunner;
			/** @var UserOptionsLookup */
			private $userOptionsLookup;

			/**
			 * @param CirrusTestCaseTrait $testCase
			 * @param CirrusSearchHookRunner $cirrusHookRunner
			 * @param UserOptionsLookup $userOptionsLookup
			 */
			public function __construct( $testCase, $cirrusHookRunner, $userOptionsLookup ) {
				$this->testCase = $testCase;
				$this->cirrusHookRunner = $cirrusHookRunner;
				$this->userOptionsLookup = $userOptionsLookup;
			}

			public function getFactory( SearchConfig $config ): SearchProfileServiceFactory {
				return new SearchProfileServiceFactory( $this->testCase->getInterWikiResolver( $config ),
					$config, $this->testCase->localServerCacheForProfileService(),
					$this->cirrusHookRunner, $this->userOptionsLookup, new ExtensionRegistry()
				);
			}
		};
	}

	public function getInterWikiResolver( SearchConfig $config ): InterwikiResolver {
		return new EmptyInterwikiResolver( $config );
	}

	public function namespacePrefixParser(): NamespacePrefixParser {
		return new class() implements NamespacePrefixParser {
			public function parse( $query ) {
				$pieces = explode( ':', $query, 2 );
				if ( count( $pieces ) === 2 ) {
					$ns = null;
					switch ( mb_strtolower( $pieces[0] ) ) {
						case 'all':
							return [ $pieces[1], null ];
						case 'category':
							return [ $pieces[1], [ NS_CATEGORY ] ];
						case 'help':
							return [ $pieces[1], [ NS_HELP ] ];
						case 'template':
							return [ $pieces[1], [ NS_TEMPLATE ] ];
						case 'category_talk':
							return [ $pieces[1], [ NS_CATEGORY_TALK ] ];
						case 'help_talk':
							return [ $pieces[1], [ NS_HELP_TALK ] ];
						case 'template_talk':
							return [ $pieces[1], [ NS_TEMPLATE_TALK ] ];
						case 'file':
							return [ $pieces[1], [ NS_FILE ] ];
						case 'file_talk':
							return [ $pieces[1], [ NS_FILE_TALK ] ];
					}
				}
				return false;
			}
		};
	}

	/**
	 * @return CirrusSearch
	 */
	public function newEngine(): CirrusSearch {
		return new CirrusSearch( $this->newHashSearchConfig( [ 'CirrusSearchServers' => [] ] ),
			CirrusDebugOptions::defaultOptions(), $this->namespacePrefixParser(), new EmptyInterwikiResolver() );
	}

	public static function sanitizeLinkFragment( string $id ): string {
		return str_replace( ' ', '_', $id );
	}

	/**
	 * @param string|null $hostWikiID
	 * @param InterwikiResolver|null $iwResolver
	 * @return TitleHelper
	 */
	public function newTitleHelper( $hostWikiID = null, ?InterwikiResolver $iwResolver = null ): TitleHelper {
		return new class(
			$hostWikiID,
			$iwResolver ?: new EmptyInterwikiResolver(),
			static function ( $v ) {
				return self::sanitizeLinkFragment( $v );
			}
		) extends TitleHelper {
			public function __construct( $hostWikiId,
				?InterwikiResolver $interwikiResolver = null, ?callable $linkSanitizer = null
			) {
				parent::__construct( $hostWikiId, $interwikiResolver, $linkSanitizer );
			}

			public function getNamespaceText( Title $title ) {
				// We only use common namespaces in tests, if this fails or you need
				// more please adjust.
				static $canonicalNames = [
					NS_MEDIA            => 'Media',
					NS_SPECIAL          => 'Special',
					NS_MAIN             => '',
					NS_TALK             => 'Talk',
					NS_USER             => 'User',
					NS_USER_TALK        => 'User_talk',
					NS_PROJECT          => 'Project',
					NS_PROJECT_TALK     => 'Project_talk',
					NS_FILE             => 'File',
					NS_FILE_TALK        => 'File_talk',
					NS_MEDIAWIKI        => 'MediaWiki',
					NS_MEDIAWIKI_TALK   => 'MediaWiki_talk',
					NS_TEMPLATE         => 'Template',
					NS_TEMPLATE_TALK    => 'Template_talk',
					NS_HELP             => 'Help',
					NS_HELP_TALK        => 'Help_talk',
					NS_CATEGORY         => 'Category',
					NS_CATEGORY_TALK    => 'Category_talk',
				];
				return $canonicalNames[$title->getNamespace()];
			}
		};
	}

	public function newManualInterwikiResolver( SearchConfig $config ): InterwikiResolver {
		return new CirrusConfigInterwikiResolver( $config, $this->createNoOpMock( MultiHttpClient::class ),
			WANObjectCache::newEmpty(), $this->createMock( InterwikiLookup::class ) );
	}

	public function localServerCacheForProfileService(): BagOStuff {
		$bagOSTuff = new HashBagOStuff();
		$bagOSTuff->set(
			$bagOSTuff->makeKey( PhraseSuggesterProfileRepoWrapper::CIRRUSSEARCH_DIDYOUMEAN_SETTINGS ),
			[]
		);
		return $bagOSTuff;
	}

	/**
	 * @param SearchConfig $searchConfig
	 * @param string $query
	 * @return SearchQueryBuilder
	 */
	public function getNewFTSearchQueryBuilder( SearchConfig $searchConfig, string $query ): SearchQueryBuilder {
		return SearchQueryBuilder::newFTSearchQueryBuilder( $searchConfig, $query,
			$this->namespacePrefixParser(), $this->createCirrusSearchHookRunner() );
	}

	/**
	 * @param SearchConfig $config
	 * @return \CirrusSearch\Parser\QueryParser|\CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser
	 */
	public function createNewFullTextQueryParser( SearchConfig $config ) {
		return QueryParserFactory::newFullTextQueryParser( $config,
			$this->namespacePrefixParser(), $this->createCirrusSearchHookRunner() );
	}

	public function createCirrusSearchHookRunner( array $hooks = [] ): CirrusSearchHookRunner {
		return new CirrusSearchHookRunner( $this->createHookContainer( $hooks ) );
	}

	public function createStaticUserOptionsLookup( array $userMap = [], array $defaults = [] ): StaticUserOptionsLookup {
		return new StaticUserOptionsLookup( $userMap, $defaults );
	}

	/**
	 * @param callable[] $hooks
	 * @return HookContainer
	 */
	abstract protected function createHookContainer( $hooks = [] );
}
