<?php

namespace CirrusSearch\Maintenance;
use CirrusSearch\Connection;

use Elastica\Client;

/**
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

/**
 * Utility class to manage a multipurpose metadata storage index for cirrus.
 * This store is used to store persistent states related to administrative
 * tasks (index settings upgrade, frozen indices, ...).
 */
class MetaStoreIndex {
	/**
	 * @const int major version, increment when adding an incompatible change
	 * to settings or mappings
	 */
	const METASTORE_MAJOR_VERSION = 0;

	/**
	 * @const int minor version increment only when adding a new field to
	 * an existing mapping or a new mapping
	 */
	const METASTORE_MINOR_VERSION = 1;

	/**
	 * @const string the doc id used to store version information related
	 * to the meta store itself. This value is not supposed to be changed.
	 */
	const METASTORE_VERSION_DOCID = 'metastore_version';

	/**
	 * @const string index name
	 */
	const INDEX_NAME = 'mw_cirrus_metastore';

	/**
	 * @const string previous index name (bc code)
	 */
	const OLD_INDEX_NAME = 'mw_cirrus_versions';

	/**
	 * @const string type for storing version tracking info
	 */
	const VERSION_TYPE = 'version';

	/**
	 * @const string type for storing sanitze jobs tracking info
	 */
	const SANITIZE_TYPE = 'sanitize';

	/**
	 * @const string type for storing frozen indices tracking info
	 */
	const FROZEN_TYPE = 'frozen';

	/**
	 * @const string type for storing internal data
	 */
	const INTERNAL_TYPE = 'internal';

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var \Elastica\Client
	 */
	private $client;

	/**
	 * @var Maintenance|null initiator maintenance script
	*/
	private $out;

	/**
	 * @var string master operation timeout
	 */
	private $masterTimeout;

	/**
	 * @var ConfigUtils
	 */
	private $configUtils;

	/**
	 * @param Connection $connection
	 * @param Maintenance $out
	 * @param $masterTimeout int
	 */
	public function __construct( Connection $connection, Maintenance $out, $masterTimeout = '10000s' ) {
		$this->connection = $connection;
		$this->client = $connection->getClient();
		$this->configUtils = new ConfigUtils( $this->client, $out );
		$this->out = $out;
		$this->masterTimeout = $masterTimeout;
	}

	public function createOrUpgradeIfNecessary() {
		$this->fixOldName();
		$status = $this->client->getStatus();
		// If the mw_cirrus_metastore alias still not exists it
		// means we need to create everything from scratch.
		if ( !$status->aliasExists( self::INDEX_NAME ) ) {
			$this->log( self::INDEX_NAME . " missing creating.\n" );
			$newIndex = $this->createNewIndex();
			$this->switchAliasTo( $newIndex );
		} else {
			list( $major, $minor ) = $this->metastoreVersion();
			if ( $major < self::METASTORE_MAJOR_VERSION ) {
				$this->log( self::INDEX_NAME . " major version mismatch upgrading.\n" );
				$this->majorUpgrade();
			} elseif( $major == self::METASTORE_MAJOR_VERSION && $minor < self::METASTORE_MINOR_VERSION ) {
				$this->log( self::INDEX_NAME . " minor version mismatch trying to upgrade mapping.\n" );
				$this->minorUpgrade();
			} elseif ( $major > self::METASTORE_MAJOR_VERSION || $minor > self::METASTORE_MINOR_VERSION ) {
				throw new \Exception( "Metastore version $major.$minor found, cannot upgrade to a lower version: " . self::METASTORE_MAJOR_VERSION . "." . self::METASTORE_MINOR_VERSION );
			}
		}
	}

	/**
	 * Create a new metastore index.
	 * @param string $suffix index suffix
	 * @return \Elastica\Index the newly created index
	 */
	public function createNewIndex( $suffix = 'first' ) {
		$name = self::INDEX_NAME . '_' . $suffix;
		$this->log( "Creating metastore index... $name" );
		// Don't forget to update METASTORE_MAJOR_VERSION when changing something
		// in the settings
		$settings = array(
			'number_of_shards' => 1,
			'auto_expand_replicas' => '0-2'
		);
		$args = array(
			'settings' => $settings,
			'mappings' => $this->buildMapping(),
		);
		// @todo utilize $this->getIndex()->create(...) once it supports setting
		// the master_timeout parameter.
		$index = $this->client->getIndex( $name );
		$index->request(
			'',
			\Elastica\Request::PUT,
			$args,
			array( 'master_timeout' => $this->masterTimeout )
		);
		$this->log( " ok\n" );
		$this->configUtils->waitForGreen( $index->getName(), 3600 );
		$this->storeMetastoreVersion( $index );
		return $index;
	}

	/**
	 * Increment :
	 *   - self:METASTORE_MAJOR_VERSION for incompatible changes
	 *   - self:METASTORE_MINOR_VERSION when adding new field or new mappings
	 * @return array[] the mapping
	 */
	private function buildMapping() {
		return array(
			self::VERSION_TYPE => array(
				'properties' => array(
					'analysis_maj' => array( 'type' => 'long', 'include_in_all' => false ),
					'analysis_min' => array( 'type' => 'long', 'include_in_all' => false ),
					'mapping_maj' => array( 'type' => 'long', 'include_in_all' => false ),
					'mapping_min' => array( 'type' => 'long', 'include_in_all' => false ),
					'shard_count' => array( 'type' => 'long', 'include_in_all' => false ),
				),
			),
			self::FROZEN_TYPE => array(
				'properties' => array(),
			),
			self::SANITIZE_TYPE => array(
				'properties' => array(),
			),
			self::INTERNAL_TYPE => array(
				'properties' => array(
					'metastore_major_version' => array(
						'type' => 'integer'
					),
					'metastore_minor_version' => array(
						'type' => 'integer'
					),
				),
			),
		);
	}

	private function minorUpgrade() {
		$index = $this->connection->getIndex( self::INDEX_NAME );
		foreach( $this->buildMapping() as $type => $mapping ) {
			$index->getType( $type )->request(
				'_mapping',
				\Elastica\Request::PUT,
				$mapping,
				array(
					'master_timeout' => $this->masterTimeout,
				)
			);
		}
		$this->storeMetastoreVersion( $index );
	}

	/**
	 * Switch the mw_cirrus_metastore alias to this new index name.
	 * @param \Elastica\Index $index
	 */
	private function switchAliasTo( $index ) {
		$name = $index->getName();
		$oldIndexName = $this->getAliasedIndexName();
		if ( $oldIndexName !== null ) {
			$this->log( "Switching " . self::INDEX_NAME . " alias from $oldIndexName to $name.\n" );
		} else {
			$this->log( "Creating " . self::INDEX_NAME . " alias to $name.\n" );
		}

		if ( $oldIndexName == $name ) {
			throw new \Exception( "Cannot switch aliases old and new index names are identical: $name" );
		}
		// Create the alias
		$path = '_aliases';
		$data = array( 'actions' => array(
			array(
				'add' => array(
					'index' => $name,
					'alias' => self::INDEX_NAME,
				)
			),
		) );
		if ( $oldIndexName !== null ) {
			$data['actions'][] = array(
					'remove' => array(
						'index' => $oldIndexName,
						'alias' => self::INDEX_NAME,
					)
				);
		}
		$this->client->request( $path, \Elastica\Request::POST, $data,
			array( 'master_timeout' => $this->masterTimeout ) );
		if ( $oldIndexName !== null ) {
			$this->log( "Deleting old index $oldIndexName\n" );
			$this->connection->getIndex( $oldIndexName )->delete();
		}
	}

	/**
	 * @return string|null the current index behind the self::INDEX_NAME
	 * alias or null if the alias does not exist
	 */
	private function getAliasedIndexName() {
		$resp = $this->client->request( '_aliases/' . self::INDEX_NAME, \Elastica\Request::GET, array() );
		$indexName = null;
		foreach( $resp->getData() as $index => $aliases ) {
			if ( isset( $aliases['aliases'][self::INDEX_NAME] ) ) {
				if ( $indexName !== null ) {
					throw new \Exception( "Multiple indices are aliased with " . self::INDEX_NAME . ", please fix manually." );
				}
				$indexName = $index;
			}
		}
		return $indexName;
	}


	private function majorUpgrade() {
		$plugins = $this->configUtils->scanAvailableModules();
		if ( !array_search( 'reindex', $plugins ) ) {
			throw new \Exception( "The reindex module is mandatory to upgrade the metastore" );
		}
		$index = $this->createNewIndex( (string) time() );
		// Reindex everything except the internal type, it's not clear
		// yet if we just need to filter the metastore version info or
		// the whole internal type. Currently we only use the internal
		// type for storing the metastore version.
		$reindex = array(
			'source' => array (
				'index' => self::INDEX_NAME,
				'query' => array(
					'bool' => array(
						'must_not' => array(
							'type' => array ( 'value' => self::INTERNAL_TYPE )
						),
					)
				),
			),
			'dest' => array( 'index' => $index->getName() ),
		);
		// reindex is extremely fast so we can wait for it
		// we might consider using the task manager if this process
		// becomes longer and/or prone to curl timeouts
		$resp = $this->client->request( '_reindex',
			\Elastica\Request::POST,
			$reindex,
			array( 'wait_for_completion' => true )
		);
		$index->refresh();
		$this->switchAliasTo( $index );
	}

	/**
	 * BC strategy to reuse mw_cirrus_versions as the new mw_cirrus_metastore
	 * If mw_cirrus_versions exists with no mw_cirrus_metastore
	 */
	private function fixOldName() {
		$status = $this->client->getStatus();
		if ( !$status->indexExists( self::OLD_INDEX_NAME ) ) {
			return;
		}
		// Old mw_cirrus_versions exists, if mw_cirrus_metastore alias does not
		// exist we must create it
		if ( !$status->aliasExists( self::INDEX_NAME ) ) {
			$this->log( "Adding transition alias to " . self::OLD_INDEX_NAME . "\n" );
			// Old one exists but new one does not
			// we need to create an alias
			$index = $this->client->getIndex( self::OLD_INDEX_NAME );
			$this->switchAliasTo( $index );
			// The version check (will return 0.0 for
			// mw_cirrus_versions) should schedule an minor or
			// major upgrade.
		}
	}

	/**
	 * @return int[] major, minor version
	 */
	public function metastoreVersion() {
		return self::getMetastoreVersion( $this->connection );
	}

	/**
	 * @return int[] major, minor version
	 */
	public function runtimeVersion() {
		return array( self::METASTORE_MAJOR_VERSION, self::METASTORE_MINOR_VERSION );
	}

	/**
	 * @param \Elastica\Index $index new index
	 */
	private function storeMetastoreVersion( $index ) {
		$index->getType( self::INTERNAL_TYPE )->addDocument(
			new \Elastica\Document(
				self::METASTORE_VERSION_DOCID,
				array(
					'metastore_major_version' => self::METASTORE_MAJOR_VERSION,
					'metastore_minor_version' => self::METASTORE_MINOR_VERSION,
				)
			)
		);
	}

	/**
	 * @param string $msg log message
	 */
	private function log( $msg ) {
		if ($this->out ) {
			$this->out->output( $msg );
		}
	}

	/**
	 * Get the version tracking index type
	 * @return \Elastica\Type
	 */
	public function versionType() {
		return self::getVersionType( $this->connection );
	}

	/**
	 * Get the frozen indices tracking index type
	 * @return \Elastica\Type $type
	 */
	public function frozenType() {
		return self::getFrozenType( $this->connection );
	}

	/**
	 * Get the sanitize tracking index type
	 * @return \Elastica\Type $type
	 */
	public function sanitizeType() {
		return self::getSanitizeType( $this->connection );
	}

	/**
	 * Get the internal index type
	 * @return \Elastica\Type $type
	 */
	private function internalType() {
		return self::getInternalType( $this->connection );
	}

	/**
	 * Get the version tracking index type
	 * @param Connection $connection
	 * @return \Elastica\Type $type
	 */
	public static function getVersionType( Connection $connection ) {
		if ( self::getMetastoreVersion( $connection ) == array( 0, 0 ) ) {
			// BC code
			return $connection->getIndex( 'mw_cirrus_versions' )->getType( 'version' );
		}
		return $connection->getIndex( self::INDEX_NAME )->getType( self::VERSION_TYPE );
	}

	/**
	 * Get the sanitize tracking index type
	 * @param Connection $connection
	 * @return \Elastica\Type $type
	 */
	public static function getSanitizeType( Connection $connection ) {
		return $connection->getIndex( self::INDEX_NAME )->getType( self::SANITIZE_TYPE );
	}

	/**
	 * Get the frozen indices tracking index type
	 * @param Connection $connection
	 * @return \Elastica\Type $type
	 */
	public static function getFrozenType( Connection $connection ) {
		$version = self::getMetastoreVersion( $connection );
		if ( $version == array( 0, 0 ) ) {
			// BC code
			global $wgCirrusSearchCreateFrozenIndex;
			$index = $connection->getIndex( 'mediawiki_cirrussearch_frozen_indexes' );
			if ( $wgCirrusSearchCreateFrozenIndex ) {
				if ( !$index->exists() ) {
					$options = array(
						'number_of_shards' => 1,
						'auto_expand_replicas' => '0-2',
					 );
					$index->create( $options, true );
				}
			}
			return $index->getType( 'name' );
		}
		return $connection->getIndex( self::INDEX_NAME )->getType( self::FROZEN_TYPE );
	}

	/**
	 * Get the sanitize tracking index type
	 * @param Connection $connection
	 * @return \Elastica\Type $type
	 */
	private static function getInternalType( Connection $connection ) {
		return $connection->getIndex( self::INDEX_NAME )->getType( self::INTERNAL_TYPE );
	}

	/**
	 * Check if cirrus is ready by checking if some indices have been created on this cluster
	 * @param Connection $connection
	 * @return bool
	 */
	public static function cirrusReady( Connection $connection ) {
		return $connection->getIndex( self::INDEX_NAME )->exists() ||
			$connection->getIndex( self::OLD_INDEX_NAME )->exists();
	}

	/**
	 * @param Connection $connection
	 * @return int[] the major and minor version of the meta store
	 * [0, 0] means that the metastore has never been created
	 */
	public static function getMetastoreVersion( Connection $connection ) {
		try {
			// @todo: do we need to cache this query?
			// that would cause 1 extra query per update
			$doc = self::getInternalType( $connection )->getDocument( self::METASTORE_VERSION_DOCID );
		} catch ( \Elastica\Exception\NotFoundException $e ) {
			return array( 0, 0 );
		} catch( \Elastica\Exception\ResponseException $e ) {
			// BC code in case the metastore alias does not exist yet
			$fullError = $e->getResponse()->getFullError();
			if ( isset( $fullError['type'] )
				&& $fullError['type'] === 'index_not_found_exception'
				&& isset( $fullError['index'] )
				&& $fullError['index'] === self::INDEX_NAME
			) {
				return array( 0, 0 );
			}
			throw $e;
		}
		return array(
			(int) $doc->get('metastore_major_version'),
			(int) $doc->get('metastore_minor_version')
		);
	}
}
