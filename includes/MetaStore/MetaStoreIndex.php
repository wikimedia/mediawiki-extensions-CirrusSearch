<?php

namespace CirrusSearch\MetaStore;

use CirrusSearch\Connection;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\AnalysisFilter;
use CirrusSearch\Maintenance\ConfigUtils;
use CirrusSearch\Maintenance\Printer;
use CirrusSearch\SearchConfig;
use MediaWiki\Status\Status;

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
 * tasks (index settings upgrade, wiki namespace names, ...).
 */
class MetaStoreIndex {
	/**
	 * @const int version of the index, increment when mappings change
	 */
	private const METASTORE_VERSION = 4;

	/**
	 * @const string the doc id used to store version information related
	 * to the meta store itself. This value is not supposed to be changed.
	 */
	private const METASTORE_VERSION_DOCID = 'metastore_version';

	/**
	 * @const string index name
	 */
	public const INDEX_NAME = 'mw_cirrus_metastore';

	/**
	 * @const string type for storing internal data
	 */
	private const INTERNAL_TYPE = 'internal';

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var \Elastica\Client
	 */
	private $client;

	/**
	 * @var Printer|null output handler
	 */
	private $out;

	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var ConfigUtils
	 */
	private $configUtils;

	/**
	 * @param Connection $connection
	 * @param Printer $out
	 * @param SearchConfig $config
	 */
	public function __construct(
		Connection $connection, Printer $out, SearchConfig $config
	) {
		$this->connection = $connection;
		$this->client = $connection->getClient();
		$this->configUtils = new ConfigUtils( $this->client, $out );
		$this->out = $out;
		$this->config = $config;
	}

	/**
	 * @return MetaVersionStore
	 */
	public function versionStore() {
		return new MetaVersionStore( $this->elasticaIndex(), $this->connection );
	}

	/**
	 * @return MetaNamespaceStore
	 */
	public function namespaceStore() {
		return new MetaNamespaceStore( $this->elasticaIndex(), $this->config->getWikiId() );
	}

	/**
	 * @return MetaSaneitizeJobStore
	 */
	public function saneitizeJobStore() {
		return new MetaSaneitizeJobStore( $this->elasticaIndex() );
	}

	/**
	 * @return MetaStore[]
	 */
	public function stores() {
		return [
			'version' => $this->versionStore(),
			'namespace' => $this->namespaceStore(),
			'saneitize' => $this->saneitizeJobStore(),
		];
	}

	/**
	 * @return Status with on success \Elastica\Index|null Index on creation, or null if the index
	 *  already exists.
	 */
	public function createIfNecessary(): Status {
		// If the mw_cirrus_metastore alias does not exists it
		// means we need to create everything from scratch.
		if ( $this->cirrusReady() ) {
			return Status::newGood();
		}
		$status = $this->configUtils->checkElasticsearchVersion();
		if ( !$status->isOK() ) {
			return $status;
		}
		$this->log( self::INDEX_NAME . " missing, creating new metastore index.\n" );
		$newIndex = $this->createNewIndex();
		$this->switchAliasTo( $newIndex );
		return Status::newGood( $newIndex );
	}

	public function createOrUpgradeIfNecessary(): Status {
		$newIndexStatus = $this->createIfNecessary();
		if ( $newIndexStatus->isOK() && $newIndexStatus->getValue() === null ) {
			$version = $this->metastoreVersion();
			if ( $version < self::METASTORE_VERSION ) {
				$this->log( self::INDEX_NAME . " version mismatch, upgrading.\n" );
				$this->upgradeIndexVersion();
			} elseif ( $version > self::METASTORE_VERSION ) {
				return Status::newFatal( "Metastore version $version found, cannot upgrade to a lower version: " .
					self::METASTORE_VERSION
				);
			}
		}
		return Status::newGood();
	}

	private function buildIndexConfiguration() {
		$pluginsStatus = $this->configUtils->scanAvailablePlugins(
			$this->config->get( 'CirrusSearchBannedPlugins' ) );
		if ( !$pluginsStatus->isGood() ) {
			throw new \RuntimeException( (string)$pluginsStatus );
		}
		$filter = new AnalysisFilter();
		[ $analysis, $mappings ] = $filter->filterAnalysis(
			// Why 'aa'? It comes first? Hoping it receives generic language treatment.
			( new AnalysisConfigBuilder( 'aa', $pluginsStatus->getValue() ) )->buildConfig(),
			$this->buildMapping()
		);

		return [
			// Don't forget to update METASTORE_VERSION when changing something
			// in the settings.
			'settings' => [
				'index' => [
					'number_of_shards' => 1,
					'auto_expand_replicas' => '0-2',
					'analysis' => $analysis,
				]
			],
			'mappings' => $mappings,
		];
	}

	/**
	 * Create a new metastore index.
	 * @param string $suffix index suffix
	 * @return \Elastica\Index the newly created index
	 */
	private function createNewIndex( $suffix = 'first' ) {
		$name = self::INDEX_NAME . '_' . $suffix;
		$this->log( "Creating metastore index... $name" );
		// @todo utilize $this->getIndex()->create(...) once it supports setting
		// the master_timeout parameter.
		$index = $this->client->getIndex( $name );
		$index->request(
			'',
			\Elastica\Request::PUT,
			$this->buildIndexConfiguration(),
			[
				'master_timeout' => $this->getMasterTimeout(),
			]
		);
		$this->log( " ok\n" );
		$this->configUtils->waitForGreen( $index->getName(), 3600 );
		$this->storeMetastoreVersion( $index );
		return $index;
	}

	/**
	 * Don't forget to update METASTORE_VERSION when changing something
	 * in the settings.
	 *
	 * @return array the mapping
	 */
	private function buildMapping() {
		$properties = [
			'type' => [ 'type' => 'keyword' ],
			'wiki' => [ 'type' => 'keyword' ],
		];

		foreach ( $this->stores() as $store ) {
			// TODO: Reuse field definition implementations from page indices?
			$storeProperties = $store->buildIndexProperties();
			if ( !$storeProperties ) {
				continue;
			}
			$overlap = array_intersect_key( $properties, $storeProperties );
			if ( $overlap ) {
				throw new \RuntimeException( 'Metastore property overlap on: ' . implode( ', ', array_keys( $overlap ) ) );
			}
			$properties += $storeProperties;
		}

		return [
			'dynamic' => false,
			'properties' => $properties,
		];
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
			throw new \RuntimeException(
				"Cannot switch aliases old and new index names are identical: $name"
			);
		}
		// Create the alias
		$path = '_aliases';
		$data = [ 'actions' => [
			[
				'add' => [
					'index' => $name,
					'alias' => self::INDEX_NAME,
				]
			],
		] ];
		if ( $oldIndexName !== null ) {
			$data['actions'][] = [
					'remove' => [
						'index' => $oldIndexName,
						'alias' => self::INDEX_NAME,
					]
				];
		}
		$this->client->request( $path, \Elastica\Request::POST, $data,
			[ 'master_timeout' => $this->getMasterTimeout() ] );
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
		// FIXME: Elastica seems to have trouble parsing the error reason
		// for this endpoint. Running a simple HEAD first to check if it
		// exists
		$resp = $this->client->request( '_alias/' . self::INDEX_NAME, \Elastica\Request::HEAD, [] );
		if ( $resp->getStatus() === 404 ) {
			return null;
		}
		$resp = $this->client->request( '_alias/' . self::INDEX_NAME, \Elastica\Request::GET, [] );
		$indexName = null;
		foreach ( $resp->getData() as $index => $aliases ) {
			if ( isset( $aliases['aliases'][self::INDEX_NAME] ) ) {
				if ( $indexName !== null ) {
					throw new \RuntimeException( "Multiple indices are aliased with " . self::INDEX_NAME .
						", please fix manually." );
				}
				$indexName = $index;
			}
		}
		return $indexName;
	}

	private function upgradeIndexVersion() {
		$pluginsStatus = $this->configUtils->scanAvailableModules();
		if ( !$pluginsStatus->isGood() ) {
			throw new \RuntimeException( (string)$pluginsStatus );
		}
		if ( !array_search( 'reindex', $pluginsStatus->getValue() ) ) {
			throw new \RuntimeException( "The reindex module is mandatory to upgrade the metastore" );
		}
		$index = $this->createNewIndex( (string)time() );
		// Reindex everything except the internal type, it's not clear
		// yet if we just need to filter the metastore version info or
		// the whole internal type. Currently we only use the internal
		// type for storing the metastore version.
		$reindex = [
			'source' => [
				'index' => self::INDEX_NAME,
				'query' => [
					'bool' => [
						'must_not' => [
							[ 'term' => [ 'type' => self::INTERNAL_TYPE ] ]
						],
					]
				],
			],
			'dest' => [ 'index' => $index->getName() ],
		];
		// reindex is extremely fast so we can wait for it
		// we might consider using the task manager if this process
		// becomes longer and/or prone to curl timeouts
		$this->client->request( '_reindex',
			\Elastica\Request::POST,
			$reindex,
			[ 'wait_for_completion' => 'true' ]
		);
		$index->refresh();
		$this->switchAliasTo( $index );
	}

	/**
	 * @return int version of metastore index expected by runtime
	 */
	public function runtimeVersion() {
		return self::METASTORE_VERSION;
	}

	/**
	 * @param \Elastica\Index $index new index
	 */
	private function storeMetastoreVersion( $index ) {
		$index->addDocument(
			new \Elastica\Document(
				self::METASTORE_VERSION_DOCID,
				[
					'type' => self::INTERNAL_TYPE,
					'metastore_major_version' => self::METASTORE_VERSION,
				]
			)
		);
	}

	/**
	 * @param string $msg log message
	 */
	private function log( $msg ) {
		if ( $this->out ) {
			$this->out->output( $msg );
		}
	}

	public function elasticaIndex(): \Elastica\Index {
		return $this->connection->getIndex( self::INDEX_NAME );
	}

	/**
	 * Check if cirrus is ready by checking if the index has been created on this cluster
	 * @return bool
	 */
	public function cirrusReady() {
		return $this->elasticaIndex()->exists();
	}

	/**
	 * @return int the version of the meta store. 0 means that
	 *  the metastore has never been created.
	 */
	public function metastoreVersion() {
		try {
			$doc = $this->elasticaIndex()->getDocument( self::METASTORE_VERSION_DOCID );
		} catch ( \Elastica\Exception\NotFoundException $e ) {
			return 0;
		} catch ( \Elastica\Exception\ResponseException $e ) {
			// BC code in case the metastore alias does not exist yet
			$fullError = $e->getResponse()->getFullError();
			if ( isset( $fullError['type'] )
				&& $fullError['type'] === 'index_not_found_exception'
				&& isset( $fullError['index'] )
				&& $fullError['index'] === self::INDEX_NAME
			) {
				return 0;
			}
			throw $e;
		}
		return (int)$doc->get( 'metastore_major_version' );
	}

	private function getMasterTimeout() {
		return $this->config->get( 'CirrusSearchMasterTimeout' );
	}
}
