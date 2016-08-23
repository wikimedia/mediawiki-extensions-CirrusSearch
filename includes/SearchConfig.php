<?php

namespace CirrusSearch;

use Config;
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

	/**
	 * Override settings
	 * @var Config
	 */
	private $source;

	/**
	 * Wiki variables prefix.
	 * @var string
	 */
	private $prefix = '';

	/**
	 * Interwiki name for this wiki
	 * @var string
	 */
	private $interwiki;

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
	 * Create new search config for current or other wiki.
	 * @param string|null $overrideWiki Interwiki link name for wiki
	 * @param string|null $overrideName DB name for the wiki
	 */
	public function __construct( $overrideWiki = null, $overrideName = null ) {
		$this->interwiki = $overrideWiki;
		if ( $overrideWiki && $overrideName ) {
			$this->wikiId = $overrideName;
			if ( $this->wikiId != wfWikiID() ) {
				$this->source = new \HashConfig( $this->getConfigVars( $overrideName, 'wgCirrus' ) );
				$this->prefix = 'wg';
				// Re-create language object
				$this->source->set( 'wgContLang', \Language::factory( $this->source->get( 'wgLanguageCode' ) ) );
				return;
			}
		}
		$this->source = new \GlobalVarConfig();
		$this->wikiId = wfWikiID();
	}

	/**
	 * Get search config vars from other wiki's config
	 * @param string $wiki Target wiki
	 * @param string $prefix Cirrus variables prefix
	 * @return array
	 */
	private function getConfigVars( $wiki, $prefix ) {
		global $wgConf;

		$cirrusVars = array_filter( array_keys($GLOBALS),
				function($key) use($prefix) {
					if ( !isset( $GLOBALS[$key] ) || is_object( $GLOBALS[$key] ) ) {
						return false;
					}
					return strncmp( $key, $prefix, strlen($prefix) ) === 0;
				}
		);
		$cirrusVars[] = 'wgLanguageCode';
		// Hack to work around https://phabricator.wikimedia.org/T111441
		putenv( 'REQUEST_METHOD' );
		return $wgConf->getConfig( $wiki, $cirrusVars );
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function has($name) {
		return $this->source->has( $this->prefix . $name );
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function get($name) {
		if ( !$this->source->has( $this->prefix . $name ) ) {
			return null;
		}
		return $this->source->get( $this->prefix . $name );
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
			return (string) $pageId;
		} else {
			return "{$prefix}|{$pageId}";
		}
	}

	/**
	 * There are times, such as when using the Reindexer, when we aren't completely
	 * sure if these are old style numeric page id's, or new style prefixed id's.
	 * Do some magic to decide and self::makeId() when necessary.
	 *
	 * @param string|int $pageOrDocId
	 * @return string
	 */
	public function maybeMakeId( $pageOrDocId ) {
		if ( !is_string( $pageOrDocId ) || ctype_digit( $pageOrDocId ) ) {
			return $this->makeId( $pageOrDocId );
		} else {
			return $pageOrDocId;
		}
	}

	/**
	 * Convert an elasticsearch document id back into a mediawiki page id.
	 *
	 * @param string $docId Elasticsearch document id
	 * @return int Related mediawiki page id
	 */
	public function makePageId( $docId ) {
		if ( !$this->get( self::PREFIX_IDS ) ) {
			return (int)$docId;
		}

		$pieces = explode( '|', $docId );
		switch( count( $pieces ) ) {
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
	 * Get wiki's interwiki code
	 * @return string
	 */
	public function getWikiCode() {
		return $this->interwiki;
	}

	/**
	 * Get chain of elements from config array
	 * @param string $configName
	 * @param string ... list of path elements
	 * @return mixed Returns value or null if not present
	 */
	public function getElement($configName) {
		if( !$this->has( $configName ) ) {
			return null;
		}
		$data = $this->get( $configName );
		$path = func_get_args();
		array_shift( $path );
		foreach( $path as $el ) {
			if( !isset( $data[$el] ) ) {
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
	 * @retirn bool true is the cluster is writable
	 */
	public function canWriteToCluster( $cluster ) {
		return in_array( $cluster, $this->getWritableClusters() );
	}

	/**
	 * Check if this cluster is defined.
	 * NOTE: this cluster may not be available for writes.
	 *
	 * @param string $cluster
	 * @retirn bool true is the cluster is writable
	 */
	public function clusterExists( $cluster ) {
		return in_array( $cluster, $this->getAvailableClusters() );
	}

	/**
	 * Initialization of availableClusters and writableClusters
	 */
	private function initClusterConfig() {
		$this->availableClusters = array_keys( $this->get( 'CirrusSearchClusters' ) );
		if( $this->has( 'CirrusSearchWriteClusters' ) ) {
			$this->writableClusters = $this->get( 'CirrusSearchWriteClusters' );
			if( is_null( $this->writableClusters ) ) {
				$this->writableClusters = array_keys( $this->get( 'CirrusSearchClusters' ) );
			}
		} else {
			$this->writableClusters = $this->availableClusters;
		}
	}
}
