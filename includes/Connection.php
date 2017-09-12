<?php

namespace CirrusSearch;

use ElasticaConnection;
use Exception;
use MWNamespace;
use CirrusSearch\Maintenance\MetaStoreIndex;

/**
 * Forms and caches connection to Elasticsearch as well as client objects
 * that contain connection information like \Elastica\Index and \Elastica\Type.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class Connection extends ElasticaConnection {
	/**
	 * Name of the index that holds content articles.
	 * @var string
	 */
	const CONTENT_INDEX_TYPE = 'content';

	/**
	 * Name of the index that holds non-content articles.
	 * @var string
	 */
	const GENERAL_INDEX_TYPE = 'general';

	/**
	 * Name of the index that hosts content title suggestions
	 * @var string
	 */
	const TITLE_SUGGEST_TYPE = 'titlesuggest';

	/**
	 * Name of the page type.
	 * @var string
	 */
	const PAGE_TYPE_NAME = 'page';

	/**
	 * Name of the namespace type.
	 * @var string
	 */
	const NAMESPACE_TYPE_NAME = 'namespace';

	/**
	 * Name of the title suggest type
	 * @var string
	 */
	const TITLE_SUGGEST_TYPE_NAME = 'titlesuggest';

	/**
	 * Name of the archive type
	 * @var string
	 */
	const ARCHIVE_TYPE_NAME = 'archive';

	/**
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * @var string
	 */
	protected $cluster;

	/**
	 * @var ClusterSettings|null
	 */
	private $clusterSettings;

	/**
	 * @var Connection[]
	 */
	private static $pool = [];

	/**
	 * @param SearchConfig $config
	 * @param string|null $cluster
	 * @return Connection
	 */
	public static function getPool( SearchConfig $config, $cluster = null ) {
		if ( $cluster === null ) {
			$cluster = $config->get( 'CirrusSearchDefaultCluster' );
		}
		$wiki = $config->getWikiId();
		if ( isset( self::$pool[$wiki][$cluster] ) ) {
			return self::$pool[$wiki][$cluster];
		} else {
			return new self( $config, $cluster );
		}
	}

	/**
	 * Pool state must be cleared when forking. Also useful
	 * in tests.
	 */
	public static function clearPool() {
		self::$pool = [];
	}

	/**
	 * @param SearchConfig $config
	 * @param string|null $cluster Name of cluster to use, or
	 *  null for the default cluster.
	 */
	public function __construct( SearchConfig $config, $cluster = null ) {
		$this->config = $config;
		if ( $cluster === null ) {
			$this->cluster = $config->get( 'CirrusSearchDefaultCluster' );
		} else {
			$this->cluster = $cluster;
		}
		$this->setConnectTimeout( $this->getSettings()->getConnectTimeout() );
		// overwrites previous connection if it exists, but these
		// seemed more centralized than having the entry points
		// all call a static method unnecessarily.
		self::$pool[$config->getWikiId()][$this->cluster] = $this;
	}

	public function __sleep() {
		throw new \RuntimeException( 'Attempting to serialize ES connection' );
	}

	/**
	 * @return string
	 */
	public function getClusterName() {
		return $this->cluster;
	}

	/**
	 * @return ClusterSettings
	 */
	public function getSettings() {
		if ( $this->clusterSettings === null ) {
			$this->clusterSettings = new ClusterSettings( $this->config, $this->cluster );
		}
		return $this->clusterSettings;
	}

	/**
	 * @return string[]|array[] Either a list of hostnames, for default
	 *  connection configuration or an array of arrays giving full connection
	 *  specifications.
	 */
	public function getServerList() {
		// This clause provides backwards compatibility with previous versions
		// of CirrusSearch. Once this variable is removed cluster configuration
		// will work as expected.
		if ( $this->config->has( 'CirrusSearchServers' ) ) {
			return $this->config->get( 'CirrusSearchServers' );
		}
		$config = $this->config->getElement( 'CirrusSearchClusters', $this->cluster );
		if ( $config === null ) {
			throw new \RuntimeException( "No configuration available for cluster: {$this->cluster}" );
		}

		return $config;
	}

	/**
	 * How many times can we attempt to connect per host?
	 *
	 * @return int
	 */
	public function getMaxConnectionAttempts() {
		return $this->config->get( 'CirrusSearchConnectionAttempts' );
	}

	/**
	 * @return \Elastica\Type
	 */
	public function getFrozenIndexNameType() {
		return MetaStoreIndex::getFrozenType( $this );
	}

	/**
	 * Fetch the Elastica Type for pages.
	 * @param mixed $name basename of index
	 * @param mixed $type type of index (content or general or false to get all)
	 * @return \Elastica\Type
	 */
	public function getPageType( $name, $type = false ) {
		return $this->getIndexType( $name, $type, self::PAGE_TYPE_NAME );
	}

	/**
	 * Fetch the Elastica Type for pages.
	 * @param mixed $name basename of index
	 * @param string|bool $cirrusType type of index (content or general or false to get all)
	 * @param string $elasticType One of the self::…_TYPE_NAME constants
	 * @return \Elastica\Type
	 */
	public function getIndexType( $name, $cirrusType, $elasticType ) {
		return $this->getIndex( $name, $cirrusType )->getType( $elasticType );
	}

	/**
	 * Fetch the Elastica Type for namespaces.
	 * @param mixed $name basename of index
	 * @return \Elastica\Type
	 */
	public function getNamespaceType( $name ) {
		$type = 'general'; // Namespaces are always stored in the 'general' index.
		return $this->getIndex( $name, $type )->getType( self::NAMESPACE_TYPE_NAME );
	}

	/**
	 * Fetch the Elastica Type for archive.
	 * @param mixed $name basename of index
	 * @return \Elastica\Type
	 */
	public function getArchiveType( $name ) {
		return $this->getIndex( $name, 'general' )->getType( self::ARCHIVE_TYPE_NAME );
	}

	/**
	 * Get all index types we support, content, general, plus custom ones
	 *
	 * @return string[]
	 */
	public function getAllIndexTypes() {
		return array_merge( array_values( $this->config->get( 'CirrusSearchNamespaceMappings' ) ),
			[ self::CONTENT_INDEX_TYPE, self::GENERAL_INDEX_TYPE ] );
	}

	/**
	 * @param string $name
	 * @return string
	 * @throws Exception
	 */
	public function extractIndexSuffix( $name ) {
		$matches = [];
		$possible = implode( '|', array_map( 'preg_quote', $this->getAllIndexTypes() ) );
		if ( !preg_match( "/_($possible)_[^_]+$/", $name, $matches ) ) {
			throw new Exception( "Can't parse index name: $name" );
		}

		return $matches[1];
	}

	/**
	 * Get the index suffix for a given namespace
	 * @param int $namespace A namespace id
	 * @return string
	 */
	public function getIndexSuffixForNamespace( $namespace ) {
		$mappings = $this->config->get( 'CirrusSearchNamespaceMappings' );
		if ( isset( $mappings[$namespace] ) ) {
			return $mappings[$namespace];
		}
		$defaultSearch = $this->config->get( 'NamespacesToBeSearchedDefault' );
		if ( isset( $defaultSearch[$namespace] ) && $defaultSearch[$namespace] ) {
			return self::CONTENT_INDEX_TYPE;
		}

		return MWNamespace::isContent( $namespace ) ?
			self::CONTENT_INDEX_TYPE : self::GENERAL_INDEX_TYPE;
	}

	/**
	 * Is there more then one namespace in the provided index type?
	 *
	 * @param string $indexType an index type
	 * @return false|int false if the number of indexes is unknown, an integer if it is known
	 */
	public function namespacesInIndexType( $indexType ) {
		if ( $indexType === self::GENERAL_INDEX_TYPE ) {
			return false;
		}

		$mappings = $this->config->get( 'CirrusSearchNamespaceMappings' );
		$inIndexType = [];
		foreach ( $mappings as $ns => $type ) {
			if ( $indexType === $type ) {
				$inIndexType[$ns] = true;
			}
		}
		if ( $indexType === self::CONTENT_INDEX_TYPE ) {
			// The content namespace includes everything set in the mappings to content (count right now)
			// Plus everything in wgContentNamespaces that isn't already in namespace mappings
			$contentNamespaces = $this->config->get( 'ContentNamespaces' );
			foreach ( $contentNamespaces as $ns ) {
				if ( !isset( $mappings[$ns] ) ) {
					$inIndexType[$ns] = true;
				}
			}
			$defaultSearch = $this->config->get( 'NamespacesToBeSearchedDefault' );
			foreach ( $defaultSearch as $ns => $shouldSearch ) {
				if ( $shouldSearch && !isset( $mappings[$ns] ) ) {
					$inIndexType[$ns] = true;
				}
			}
		}
		return count( $inIndexType );
	}

	/**
	 * @param int[]|null $namespaces List of namespaces to check
	 * @return string|false The suffix to use (e.g. content or general) to
	 *  query the namespaces, or false if both need to be queried.
	 */
	public function pickIndexTypeForNamespaces( array $namespaces = null ) {
		$indexTypes = [];
		if ( $namespaces ) {
			foreach ( $namespaces as $namespace ) {
				$indexTypes[] = $this->getIndexSuffixForNamespace( $namespace );
			}
			$indexTypes = array_unique( $indexTypes );
		}
		if ( count( $indexTypes ) === 1 ) {
			return $indexTypes[0];
		} else {
			return false;
		}
	}

	/**
	 * @param int[]|null $namespaces List of namespaces to check
	 * @return string[] the list of all index suffixes mathing the namespaces
	 */
	public function getAllIndexSuffixesForNamespaces( $namespaces = null ) {
		if ( $namespaces ) {
			foreach ( $namespaces as $namespace ) {
				$indexTypes[] = $this->getIndexSuffixForNamespace( $namespace );
			}
			return array_unique( $indexTypes );
		}
		// If no namespaces provided all indices are needed
		$mappings = $this->config->get( 'CirrusSearchNamespaceMappings' );
		return array_merge( [ self::CONTENT_INDEX_TYPE, self::GENERAL_INDEX_TYPE ],
			array_values( $mappings ) );
	}

	public function destroyClient() {
		self::$pool = [];
		parent::destroyClient();
	}

	/**
	 * @param string[] $clusters array of cluster names
	 * @param SearchConfig $config the search config
	 * @return Connection[] array of connection indexed by cluster name
	 */
	public static function getClusterConnections( array $clusters, SearchConfig $config ) {
		$connections = [];
		foreach ( $clusters as $name ) {
			$connections[$name] = self::getPool( $config, $name );
		}
		return $connections;
	}

	/**
	 * @param SearchConfig $config the search config
	 * @return Connection[] array of connection indexed by cluster name.
	 */
	public static function getWritableClusterConnections( SearchConfig $config ) {
		return self::getClusterConnections( $config->getWritableClusters(), $config );
	}

	/**
	 * @param SearchConfig $config the search config
	 * @return Connection[] array of connection indexed by cluster name.
	 */
	public static function getAllClusterConnections( SearchConfig $config ) {
		return self::getClusterConnections( $config->getAvailableClusters(), $config );
	}
}
