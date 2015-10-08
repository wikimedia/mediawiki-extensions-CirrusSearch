<?php

namespace CirrusSearch;
use \ElasticaConnection;
use \MWNamespace;

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
	private static $pool = array();

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
		self:$pool = array();
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
	 * @return array(string)
	 */
	public function getServerList() {
		// This clause provides backwards compatability with previous versions
		// of CirrusSearch. Once this variable is removed cluster configuration
		// will work as expected.
		if ( $this->config->has( 'CirrusSearchServers' ) ) {
			return $this->config->get( 'CirrusSearchServers' );
		} else {
			return $this->config->getElement( 'CirrusSearchClusters', $this->cluster );
		}
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
	 * Fetch the Elastica Type used for all wikis in the cluster to track
	 * frozen indexes that should not be written to.
	 * @return \Elastica\Index
	 */
	public function getFrozenIndex() {
		$index = $this->getIndex( 'mediawiki_cirrussearch_frozen_indexes' );
		if ( !$index->exists() ) {
			$options = array(
				'number_of_shards' => 1,
				'auto_expand_replicas' => '0-2',
			 );
			$index->create( $options, true );
		}
		return $index;
	}

	/**
	 * @return \Elastica\Type
	 */
	public function getFrozenIndexNameType() {
		return $this->getFrozenIndex()->getType( 'name' );
	}

	/**
	 * Fetch the Elastica Type for pages.
	 * @param mixed $name basename of index
	 * @param mixed $type type of index (content or general or false to get all)
	 * @return \Elastica\Type
	 */
	public function getPageType( $name, $type = false ) {
		return $this->getIndex( $name, $type )->getType( self::PAGE_TYPE_NAME );
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
	 * Get all index types we support, content, general, plus custom ones
	 *
	 * @return array(string)
	 */
	public function getAllIndexTypes() {
		return array_merge( array_values( $this->config->get( 'CirrusSearchNamespaceMappings' ) ),
			array( self::CONTENT_INDEX_TYPE, self::GENERAL_INDEX_TYPE ) );
	}

	/**
	 * Given a list of Index objects, return the 'type' portion of the name
	 * of that index. This matches the types as returned from
	 * self::getAllIndexTypes().
	 *
	 * @param \Elastica\Index[] $indexes
	 * @return string[]
	 */
	public function indexToIndexTypes( array $indexes ) {
		$allowed = $this->getAllIndexTypes();
		return array_map( function( $type ) use ( $allowed ) {
			$fullName = $type->getIndex()->getName();
			$parts = explode( '_', $fullName );
			// In 99% of cases it should just be the second
			// piece of a 2 or 3 part name.
			if ( isset( $parts[1] ) && in_array( $parts[1], $allowed ) ) {
				return $parts[1];
			}
			// the wikiId should be the first part of the name and
			// not part of our result, strip it
			if ( $parts[0] === wfWikiId() ) {
				$parts = array_slice( $parts, 1 );
			}
			// there might be a suffix at the end, try stripping it
			$maybe = implode( '_', array_slice( $parts, 0, -1 ) );
			if ( in_array( $maybe, $allowed ) ) {
				return $maybe;
			}
			// maybe there wasn't a suffix at the end, try the whole thing
			$maybe = implode( '_', $parts );
			if ( in_array( $maybe, $allowed ) ) {
				return $maybe;
			}
			// probably not right, but at least we tried
			return $fullName;
		}, $indexes );
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

		return MWNamespace::isContent( $namespace ) ?
			self::CONTENT_INDEX_TYPE : self::GENERAL_INDEX_TYPE;
	}

	/**
	 * Is there more then one namespace in the provided index type?
	 * @var string $indexType an index type
	 * @return false|integer false if the number of indexes is unknown, an integer if it is known
	 */
	public function namespacesInIndexType( $indexType ) {
		if ( $indexType === self::GENERAL_INDEX_TYPE ) {
			return false;
		}

		$mappings = $this->config->get( 'CirrusSearchNamespaceMappings' );
		$count = count( array_keys( $mappings, $indexType ) );
		if ( $indexType === self::CONTENT_INDEX_TYPE ) {
			// The content namespace includes everything set in the mappings to content (count right now)
			// Plus everything in wgContentNamespaces that isn't already in namespace mappings
			$contentNamespaces = $this->config->get( 'ContentNamespaces' );
			$count += count( array_diff( $contentNamespaces, array_keys( $mappings ) ) );
		}
		return $count;
	}

	public function destroyClient() {
		self::$pool = array();
		return parent::destroyClient();
	}
}
