<?php

namespace CirrusSearch;

use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Profile\SearchProfileServiceFactory;
use Config;
use MediaWiki\MediaWikiServices;
use RequestContext;

/**
 * Configuration class encapsulating Searcher environment.
 * This config class can import settings from the environment globals,
 * or from specific wiki configuration.
 */
class SearchConfig implements \Config {
	// Constants for referring to various config values. Helps prevent fat-fingers
	const INDEX_BASE_NAME = 'CirrusSearchIndexBaseName';
	const PREFIX_IDS = 'CirrusSearchPrefixIds';
	const CIRRUS_VAR_PREFIX = 'wgCirrus';

	// Magic word to tell the SearchConfig to translate INDEX_BASE_NAME into wfWikiID()
	const WIKI_ID_MAGIC_WORD = '__wikiid__';

	/** @static string[] non cirrus vars to load when loading external wiki config */
	private static $nonCirrusVars = [
		'wgLanguageCode',
		'wgContentNamespaces',
		'wgNamespacesToBeSearchedDefault',
	];

	/**
	 * Override settings
	 * @var Config
	 */
	private $source;

	/**
	 * Wiki variables prefix.
	 * @var string
	 */
	protected $prefix = '';

	/**
	 * Wiki id or null for current wiki
	 * @var string|null
	 */
	private $wikiId;

	/**
	 * @var string[]|null writable clusters (lazy loaded, call
	 * getWritableClusters() instead of direct access)
	 */
	private $writableClusters;

	/**
	 * @var string[]|null clusters available (lazy loaded, call
	 * getAvailableClusters() instead of direct access)
	 */
	private $availableClusters;

	/**
	 * @var SearchProfileService|null
	 */
	private $profileService;

	/**
	 * Create new search config for current or other wiki.
	 * NOTE: if loading another wiki config the list of variables extracted
	 * is:
	 *   - all globals with a prefix 'wgCirrus'
	 *   - all non cirrus vars defined in self::$nonCirrusVars
	 * Make sure to update this array when new vars are needed or you may encounter
	 * issues when running queries on external wiki such as TextCat lang detection
	 * see CirrusSearch::searchTextSecondTry().
	 *
	 * @param string|null $overrideName DB name for the wiki
	 */
	public function __construct( $overrideName = null ) {
		if ( $overrideName && $overrideName != wfWikiID() ) {
			$this->wikiId = $overrideName;
			$this->source = new \HashConfig( $this->getConfigVars( $overrideName, self::CIRRUS_VAR_PREFIX ) );
			$this->prefix = 'wg';
			// Re-create language object
			$this->source->set( 'wgContLang', \Language::factory( $this->source->get( 'wgLanguageCode' ) ) );
			return;
		}
		$this->source = new \GlobalVarConfig();
		$this->wikiId = wfWikiID();
	}

	/**
	 * @return bool true if this config was built for this wiki.
	 */
	public function isLocalWiki() {
		// FIXME: this test is somewhat obscure (very indirect to say the least)
		// problem is that testing $this->wikiId === wfWikiId() would not work
		// properly during unit tests.
		return $this->source instanceof \GlobalVarConfig;
	}

	/**
	 * Get search config vars from other wiki's config
	 *
	 * Public for unit test purpose only.
	 *
	 * @param string $wiki Target wiki
	 * @param string $prefix Cirrus variables prefix
	 * @return array
	 */
	public function getConfigVars( $wiki, $prefix ) {
		global $wgConf;

		$cirrusVars = array_filter( array_keys( $GLOBALS ),
				function ( $key ) use( $prefix ) {
					if ( !isset( $GLOBALS[$key] ) || is_object( $GLOBALS[$key] ) ) {
						return false;
					}
					return strncmp( $key, $prefix, strlen( $prefix ) ) === 0;
				}
		);
		$cirrusVars = array_merge( $cirrusVars, self::$nonCirrusVars );
		// Hack to work around https://phabricator.wikimedia.org/T111441
		putenv( 'REQUEST_METHOD' );
		return $wgConf->getConfig( $wiki, $cirrusVars );
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function has( $name ) {
		return $this->source->has( $this->prefix . $name );
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function get( $name ) {
		if ( !$this->source->has( $this->prefix . $name ) ) {
			return null;
		}
		$value = $this->source->get( $this->prefix . $name );
		if ( $name === self::INDEX_BASE_NAME && $value === self::WIKI_ID_MAGIC_WORD ) {
			return $this->getWikiId();
		}
		return $value;
	}

	/**
	 * Produce new configuration from globals
	 * @return SearchConfig
	 */
	public static function newFromGlobals() {
		return new self();
	}

	/**
	 * Return configured Wiki ID
	 * @return string
	 */
	public function getWikiId() {
		return $this->wikiId;
	}

	/**
	 * @todo
	 * The indices have to be rebuilt with new id's and we have to know when
	 * generating queries if new style id's are being used, or old style. It
	 * could plausibly be done with the metastore index, but that seems like
	 * overkill because the knowledge is only necessary during transition, and
	 * not post-transition.  Additionally this function would then need to know
	 * the name of the index being queried, and that isn't always known when
	 * building.
	 *
	 * @param string|int $pageId
	 * @return string
	 */
	public function makeId( $pageId ) {
		$prefix = $this->get( self::PREFIX_IDS )
			? $this->getWikiId()
			: null;

		if ( $prefix === null ) {
			return (string)$pageId;
		} else {
			return "{$prefix}|{$pageId}";
		}
	}

	/**
	 * Convert an elasticsearch document id back into a mediawiki page id.
	 *
	 * @param string $docId Elasticsearch document id
	 * @return int Related mediawiki page id
	 * @throws \Exception
	 */
	public function makePageId( $docId ) {
		if ( !$this->get( self::PREFIX_IDS ) ) {
			return (int)$docId;
		}

		$pieces = explode( '|', $docId );
		switch ( count( $pieces ) ) {
		case 2:
			return (int)$pieces[1];
		case 1:
			// Broken doc id...assume somehow this didn't get prefixed.
			// Attempt to continue on...but maybe should throw exception
			// instead?
			return (int)$docId;
		default:
			throw new \Exception( "Invalid document id: $docId" );
		}
	}

	/**
	 * Get user's language
	 * @return string User's language code
	 */
	public function getUserLanguage() {
		// I suppose using $wgLang would've been more evil than this, but
		// only marginally so. Find some real context to use here.
		return RequestContext::getMain()->getLanguage()->getCode();
	}

	/**
	 * Get chain of elements from config array
	 * @param string $configName
	 * @param string $path,... list of path elements
	 * @return mixed Returns value or null if not present
	 */
	public function getElement( $configName ) {
		if ( !$this->has( $configName ) ) {
			return null;
		}
		$data = $this->get( $configName );
		$path = func_get_args();
		array_shift( $path );
		foreach ( $path as $el ) {
			if ( !isset( $data[$el] ) ) {
				return null;
			}
			$data = $data[$el];
		}
		return $data;
	}

	/**
	 * For Unit tests
	 * @param Config $source Config override source
	 */
	protected function setSource( Config $source ) {
		$this->source = $source;
	}

	/**
	 * @return string[] array of all the cluster names defined in this config
	 */
	public function getAvailableClusters() {
		if ( $this->availableClusters === null ) {
			$this->initClusterConfig();
		}
		return $this->availableClusters;
	}

	/**
	 * @return string[] array of all the clusters allowed to receive write operations
	 */
	public function getWritableClusters() {
		if ( $this->writableClusters === null ) {
			$this->initClusterConfig();
		}
		return $this->writableClusters;
	}

	/**
	 * Check if a cluster is declared "writable".
	 * NOTE: a cluster is considered writable even if one of its index is
	 * frozen.
	 * Before sending any writes in this cluster, the forzen index status
	 * must be checked fr the  target index.
	 * @see DataSender::areIndexesAvailableForWrites()
	 *
	 * @param string $cluster
	 * @return bool
	 */
	public function canWriteToCluster( $cluster ) {
		return in_array( $cluster, $this->getWritableClusters() );
	}

	/**
	 * Check if this cluster is defined.
	 * NOTE: this cluster may not be available for writes.
	 *
	 * @param string $cluster
	 * @return bool
	 */
	public function clusterExists( $cluster ) {
		return in_array( $cluster, $this->getAvailableClusters() );
	}

	/**
	 * Initialization of availableClusters and writableClusters
	 */
	private function initClusterConfig() {
		$this->availableClusters = array_keys( $this->get( 'CirrusSearchClusters' ) );
		if ( $this->has( 'CirrusSearchWriteClusters' ) ) {
			$this->writableClusters = $this->get( 'CirrusSearchWriteClusters' );
			if ( is_null( $this->writableClusters ) ) {
				$this->writableClusters = array_keys( $this->get( 'CirrusSearchClusters' ) );
			}
		} else {
			$this->writableClusters = $this->availableClusters;
		}
	}

	/**
	 * for unit tests purpose only
	 * @return string[] list of "non-cirrus" var names
	 */
	public static function getNonCirrusConfigVarNames() {
		return self::$nonCirrusVars;
	}

	/**
	 * @return true if cross project (same language) is enabled
	 */
	public function isCrossProjectSearchEnabled() {
		if ( $this->get( 'CirrusSearchEnableCrossProjectSearch' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @return true if cross language (same project) is enabled
	 */
	public function isCrossLanguageSearchEnabled() {
		if ( $this->get( 'CirrusSearchEnableAltLanguage' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Load the SearchProfileService suited for this SearchConfig.
	 * The service is initialized thanks to SearchProfileServiceFactory
	 * that will load CirrusSearch profiles and additional extension profiles
	 *
	 * <b>NOTE:</b> extension profiles are not loaded if this config is built
	 * for a sister wiki.
	 *
	 * @return SearchProfileService
	 * @see SearchProfileService
	 * @see SearchProfileServiceFactory
	 */
	public function getProfileService() {
		if ( $this->profileService === null ) {
			/** @var SearchProfileServiceFactory $factory */
			$factory = MediaWikiServices::getInstance()
				->getService( SearchProfileServiceFactory::SERVICE_NAME );
			$this->profileService = $factory->loadService( $this );
		}
		return $this->profileService;
	}
}
