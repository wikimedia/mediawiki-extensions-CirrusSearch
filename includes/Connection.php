<?php

namespace CirrusSearch;

use Exception;
use LogicException;
use MediaWiki\Extension\Elastica\ElasticaConnection;
use MediaWiki\MediaWikiServices;
use Wikimedia\Assert\Assert;

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
	 * Suffix of the index that holds content articles.
	 */
	public const CONTENT_INDEX_SUFFIX = 'content';

	/**
	 * Suffix of the index that holds non-content articles.
	 */
	public const GENERAL_INDEX_SUFFIX = 'general';

	/**
	 * Suffix of the index that hosts content title suggestions
	 */
	public const TITLE_SUGGEST_INDEX_SUFFIX = 'titlesuggest';

	/**
	 * Suffix of the index that hosts archive data
	 */
	public const ARCHIVE_INDEX_SUFFIX = 'archive';

	/**
	 * Name of the page document type.
	 */
	public const PAGE_DOC_TYPE = 'page';

	/**
	 * Name of the title suggest document type
	 */
	public const TITLE_SUGGEST_DOC_TYPE = 'titlesuggest';

	/**
	 * Name of the archive document type
	 */
	public const ARCHIVE_DOC_TYPE = 'archive';

	/**
	 * Map of index types (suffix names) indexed by mapping type.
	 */
	private const SUFFIX_MAPPING = [
		self::PAGE_DOC_TYPE => [
			self::CONTENT_INDEX_SUFFIX,
			self::GENERAL_INDEX_SUFFIX,
		],
		self::ARCHIVE_DOC_TYPE => [
			self::ARCHIVE_INDEX_SUFFIX
		],
	];

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
	 * @var Connection[][]
	 */
	private static $pool = [];

	/**
	 * @param SearchConfig $config
	 * @param string|null $cluster
	 * @return Connection
	 */
	public static function getPool( SearchConfig $config, $cluster = null ) {
		$assignment = $config->getClusterAssignment();
		$cluster ??= $assignment->getSearchCluster();
		$wiki = $config->getWikiId();
		$clusterId = $assignment->uniqueId( $cluster );
		return self::$pool[$wiki][$clusterId] ?? new self( $config, $cluster );
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
		$assignment = $config->getClusterAssignment();
		$this->cluster = $cluster ?? $assignment->getSearchCluster();
		$this->setConnectTimeout( $this->getSettings()->getConnectTimeout() );
		// overwrites previous connection if it exists, but these
		// seemed more centralized than having the entry points
		// all call a static method unnecessarily.
		// TODO: Assumes all $config that return same wiki id have same config, but there
		// are places that expect they can wrap config with new values and use them.
		$clusterId = $assignment->uniqueId( $this->cluster );
		self::$pool[$config->getWikiId()][$clusterId] = $this;
	}

	/**
	 * @return never
	 */
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
		return $this->config->getClusterAssignment()->getServerList( $this->cluster );
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
	 * Fetch the Elastica Index for archive.
	 * @param mixed $name basename of index
	 * @return \Elastica\Index
	 */
	public function getArchiveIndex( $name ) {
		return $this->getIndex( $name, self::ARCHIVE_INDEX_SUFFIX );
	}

	/**
	 * Get all index types we support, content, general, plus custom ones
	 *
	 * @param string|null $documentType the document type name the index must support to be returned
	 * can be self::PAGE_DOC_TYPE for content and general indices but also self::ARCHIVE_DOC_TYPE
	 * for the archive index. Defaults to Connection::PAGE_DOC_TYPE.
	 * set to null to return all known index types (only suited for maintenance tasks, not for read/write operations).
	 * @return string[]
	 */
	public function getAllIndexSuffixes( $documentType = self::PAGE_DOC_TYPE ) {
		Assert::parameter( $documentType === null || isset( self::SUFFIX_MAPPING[$documentType] ),
			'$documentType', "Unknown mapping type $documentType" );
		$indexSuffixes = [];

		if ( $documentType === null ) {
			foreach ( self::SUFFIX_MAPPING as $types ) {
				$indexSuffixes = array_merge( $indexSuffixes, $types );
			}
			$indexSuffixes = array_merge(
				$indexSuffixes,
				array_values( $this->config->get( 'CirrusSearchNamespaceMappings' ) )
			);
		} else {
			$indexSuffixes = array_merge(
				$indexSuffixes,
				self::SUFFIX_MAPPING[$documentType],
				$documentType === self::PAGE_DOC_TYPE ?
					array_values( $this->config->get( 'CirrusSearchNamespaceMappings' ) ) : []
			);
		}

		if ( !$this->getSettings()->isPrivateCluster()
			|| !$this->config->get( 'CirrusSearchEnableArchive' )
		) {
			$indexSuffixes = array_filter( $indexSuffixes, static function ( $type ) {
				return $type !== self::ARCHIVE_INDEX_SUFFIX;
			} );
		}

		return $indexSuffixes;
	}

	/**
	 * @param string $name
	 * @return string
	 * @throws Exception
	 */
	public function extractIndexSuffix( $name ) {
		$matches = [];
		$possible = implode( '|', array_map( 'preg_quote', $this->getAllIndexSuffixes( null ) ) );
		if ( !preg_match( "/_($possible)_[^_]+$/", $name, $matches ) ) {
			throw new LogicException( "Can't parse index name: $name" );
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
			return self::CONTENT_INDEX_SUFFIX;
		}

		return MediaWikiServices::getInstance()->getNamespaceInfo()->isContent( $namespace ) ?
			self::CONTENT_INDEX_SUFFIX : self::GENERAL_INDEX_SUFFIX;
	}

	/**
	 * @param int[]|null $namespaces List of namespaces to check
	 * @return string|false The suffix to use (e.g. content or general) to
	 *  query the namespaces, or false if both need to be queried.
	 * @deprecated 1.38 Use self::pickIndexSuffixForNamespaces
	 */
	public function pickIndexTypeForNamespaces( ?array $namespaces = null ) {
		return $this->pickIndexSuffixForNamespaces( $namespaces );
	}

	/**
	 * @param int[]|null $namespaces List of namespaces to check
	 * @return string|false The suffix to use (e.g. content or general) to
	 *  query the namespaces, or false if all need to be queried.
	 */
	public function pickIndexSuffixForNamespaces( ?array $namespaces = null ) {
		$indexSuffixes = [];
		if ( $namespaces ) {
			foreach ( $namespaces as $namespace ) {
				$indexSuffixes[] = $this->getIndexSuffixForNamespace( $namespace );
			}
			$indexSuffixes = array_unique( $indexSuffixes );
		}
		if ( count( $indexSuffixes ) === 1 ) {
			return $indexSuffixes[0];
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
			$indexSuffixes = [];
			foreach ( $namespaces as $namespace ) {
				$indexSuffixes[] = $this->getIndexSuffixForNamespace( $namespace );
			}
			return array_unique( $indexSuffixes );
		}
		// If no namespaces provided all indices are needed
		$mappings = $this->config->get( 'CirrusSearchNamespaceMappings' );
		return array_merge( self::SUFFIX_MAPPING[self::PAGE_DOC_TYPE],
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
	 * @return SearchConfig
	 */
	public function getConfig() {
		return $this->config;
	}
}
