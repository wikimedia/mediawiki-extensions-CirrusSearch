<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\DataSender;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\Util;
use CirrusSearch\BuildDocument\SuggestBuilder;
use CirrusSearch\BuildDocument\SuggestScoringMethodFactory;
use Elastica;
use Elastica\Query;
use Elastica\Request;
use Elastica\Status;

/**
 * Update the search configuration on the search backend for the title
 * suggest index.
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

$IP = getenv( 'MW_INSTALL_PATH' );
if( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );
require_once( __DIR__ . '/../includes/Maintenance/Maintenance.php' );

class UpdateSuggesterIndex extends Maintenance {
	/**
	 * @var string language code we're building for
	 */
	private $langCode;

	private $indexChunkSize;
	private $indexRetryAttempts;

	private $indexTypeName;
	private $indexBaseName;
	private $indexIdentifier;

	/**
	 * @var SuggestScoringMethod the score function to use.
	 */
	private $scoreMethod;

	/**
	 * @var old suggester index that will be deleted at the end of the process
	 */
	private $oldIndex;

	/**
	 * @var int
	 */
	private $lastProgressPrinted;

	/**
	 * @var boolean optimize the index when done.
	 */
	private $optimizeIndex;

	/**
	 * @var array
	 */
	protected $maxShardsPerNode;

	/**
	 * @var array(String) list of available plugins
	 */
	private $availablePlugins;


	/**
	 * @var boolean index geo contextualized suggestions
	 */
	private $withGeo;

	/**
	 * @var string
	 */
	private $masterTimeout;

	/**
	 * @var ConfigUtils
	 */
	 private $utils;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Create a new suggester index. Always operates on a single cluster." );
		$this->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
		$this->addOption( 'indexChunkSize', 'Documents per shard to index in a batch.   ' .
		    'Note when changing the number of shards that the old shard size is used, not the new ' .
		    'one.  If you see many errors submitting documents in bulk but the automatic retry as ' .
		    'singles works then lower this number.  Defaults to 100.', false, true );
		$this->addOption( 'indexRetryAttempts', 'Number of times to back off and retry ' .
			'per failure.  Note that failures are not common but if Elasticsearch is in the process ' .
			'of moving a shard this can time out.  This will retry the attempt after some backoff ' .
			'rather than failing the whole reindex process.  Defaults to 5.', false, true );
		$this->addOption( 'optimize', 'Optimize the index to 1 segment. Defaults to false.', false, false );
		$this->addOption( 'with-geo', 'Build geo contextualized suggestions. Defaults to false.', false, false );
		$this->addOption( 'scoringMethod', 'The scoring method to use when computing suggestion weights. ' .
			'Detauls to quality.', false, true );
		$this->addOption( 'masterTimeout', 'The amount of time to wait for the master to respond to mapping ' .
			'updates before failing. Defaults to $wgCirrusSearchMasterTimeout.', false, true );
		$this->addOption( 'replicationTimeout', 'The amount of time (seconds) to wait for the replica shards to initialize. ' .
			'Defaults to 3600 seconds.', false, true );
		$this->addOption( 'allocationIncludeTag', 'Set index.routing.allocation.include.tag on the created index. ' .
			'Useful if you want to force the suggester index not to be allocated on a specific set of nodes.',
			false, true );
		$this->addOption( 'allocationExcludeTag', 'Set index.routing.allocation.exclude.tag on the created index. ' .
			'Useful if you want to force the suggester index not to be allocated on a specific set of nodes.',
			false, true );
	}

	public function execute() {
		global $wgLanguageCode,
			$wgCirrusSearchBannedPlugins,
			$wgCirrusSearchRefreshInterval,
			$wgPoolCounterConf,
			$wgCirrusSearchMasterTimeout;

		$this->masterTimeout = $this->getOption( 'masterTimeout', $wgCirrusSearchMasterTimeout );
		$this->indexTypeName = Connection::TITLE_SUGGEST_TYPE;

		// Check that all shards and replicas settings are set
		try {
			$this->getShardCount();
			$this->getReplicaCount();
		} catch( \Exception $e ) {
			$this->error( "Failed to get shard count and replica count information: {$e->getMessage()}", 1 );
		}

		// Make sure we don't flood the pool counter
		unset( $wgPoolCounterConf['CirrusSearch-Search'] );

		// Set the timeout for maintenance actions
		$this->setConnectionTimeout();

		$this->indexBaseName = $this->getOption( 'baseName', wfWikiId() );
		$this->indexChunkSize = $this->getOption( 'indexChunkSize', 100 );
		$this->indexRetryAttempts = $this->getOption( 'reindexRetryAttempts', 5 );

		$this->optimizeIndex = $this->getOption( 'optimize', false );
		$this->withGeo = $this->getOption( 'with-geo', false );

		$this->utils = new ConfigUtils( $this->getClient(), $this);

		$this->langCode = $wgLanguageCode;
		$this->bannedPlugins = $wgCirrusSearchBannedPlugins;

		$this->utils->checkElasticsearchVersion();

		$this->maxShardsPerNode = isset( $wgCirrusSearchMaxShardsPerNode[ $this->indexTypeName ] ) ? $wgCirrusSearchMaxShardsPerNode[ $this->indexTypeName ] : 'unlimited';
		$this->refreshInterval = $wgCirrusSearchRefreshInterval;

		try {
			if ( !$this->canWrite() ) {
				$this->error( 'Index/Cluster is frozen. Giving up.', 1 );
			}
			$oldIndexIdentifier = $this->utils->pickIndexIdentifierFromOption( 'current', $this->getIndexTypeName() );
			$this->oldIndex = $this->getConnection()->getIndex( $this->indexBaseName, $this->indexTypeName, $oldIndexIdentifier );
			$this->indexIdentifier = $this->utils->pickIndexIdentifierFromOption( 'now', $this->getIndexTypeName() );

			$this->availablePlugins = $this->utils->scanAvailablePlugins( $this->bannedPlugins );
			$this->analysisConfigBuilder = $this->pickAnalyzer( $this->langCode, $this->availablePlugins );

			$this->createIndex();
			$this->indexData();
			if ( $this->optimizeIndex ) {
				$this->optimize();
			}
			$this->enableReplicas();
			$this->validateAlias();
			$this->updateVersions();
			$this->deleteOldIndex();
			$this->output("done.\n");
		} catch ( \Elastica\Exception\Connection\HttpException $e ) {
			$message = $e->getMessage();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->error( "Http error communicating with Elasticsearch:  $message.\n", 1 );
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$type = get_class( $e );
			$message = ElasticsearchIntermediary::extractMessage( $e );
			$trace = $e->getTraceAsString();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->error( "Elasticsearch failed in an unexpected way.  This is always a bug in CirrusSearch.\n" .
				"Error type: $type\n" .
				"Message: $message\n" .
				"Trace:\n" . $trace, 1 );
		}
		$this->getIndex()->refresh();
	}

	/**
	 * Check the frozen indices
	 * @return true if the cluster/index is not frozen, false otherwise.
	 */
	private function canWrite() {
		$name = $this->getConnection()->getIndexName( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE_NAME );
		// Reuse DataSender even if we don't send anything with it.
		$sender = new DataSender( $this->getConnection() );
		return $sender->areIndexesAvailableForWrites( array( $name ) );
	}


	private function deleteOldIndex() {
		if ( $this->oldIndex && $this->oldIndex->exists() ) {
			$this->output("Deleting " . $this->oldIndex->getName() . " ... ");
			// @todo Utilize $this->oldIndex->delete(...) once Elastica library is updated
			// to allow passing the master_timeout
			$this->oldIndex->request(
				'',
				Request::DELETE,
				array(),
				array( 'master_timeout' => $this->masterTimeout )
			);
			$this->output("ok.\n");
		}
	}

	protected function setConnectionTimeout() {
		global $wgCirrusSearchMaintenanceTimeout;
		$this->getConnection()->setTimeout( $wgCirrusSearchMaintenanceTimeout );
	}

	private function optimize() {
		$this->output("Optimizing index...");
		$this->getIndex()->optimize( array( 'max_num_segments' => 1 ) );
		$this->output("ok.\n");
	}
	private function indexData() {
		$query = new Query();
		$query->setFields( array( '_id', '_type', '_source' ) );
		// Exclude content fields to save bandwidth
		$query->setSource( array(
			'exclude' => array( 'text', 'source_text', 'opening_text', 'auxiliary_text' )
		));

		$query->setQuery(
			new Elastica\Query\Filtered(
				new Elastica\Query\MatchAll(),
				new Elastica\Filter\BoolAnd( array(
					new Elastica\Filter\Type( Connection::PAGE_TYPE_NAME ),
					new Elastica\Filter\Term( array( "namespace" => NS_MAIN ) )
				) )
			)
		);

		$scrollOptions = array(
			'search_type' => 'scan',
			'scroll' => "15m",
			'size' => $this->indexChunkSize
		);


		// TODO: only content index for now ( we'll have to check how it works with commons )
		$sourceIndex = $this->getConnection()->getIndex( $this->indexBaseName, Connection::CONTENT_INDEX_TYPE );
		$result = $sourceIndex->search( $query, $scrollOptions );
		$totalDocsInIndex = $result->getResponse()->getData();
		$totalDocsInIndex = $totalDocsInIndex['hits']['total'];
		$totalDocsToDump = $totalDocsInIndex;

		$scoreMethodName = $this->getOption( 'scoringMethod', 'quality' );
		$this->scoreMethod = SuggestScoringMethodFactory::getScoringMethod( $scoreMethodName, $totalDocsInIndex );
		$builder = new SuggestBuilder( $this->scoreMethod, $this->withGeo );

		$docsDumped = 0;
		$this->output( "Indexing $totalDocsToDump documents ($totalDocsInIndex in the index)\n" );
		$self = $this;

		$destinationType = $this->getIndex()->getType( Connection::TITLE_SUGGEST_TYPE_NAME );
		$retryAttempts = $this->indexRetryAttempts;

		Util::iterateOverScroll( $sourceIndex, $result->getResponse()->getScrollId(), '15m',
			function( $results ) use ( $self, &$docsDumped, $totalDocsToDump, $builder,
					$destinationType, $retryAttempts ) {
				$suggestDocs = array();
				foreach ( $results as $result ) {
					$docsDumped++;
					$suggests = $builder->build( $result->getId(), $result->getSource() );
					foreach ( $suggests as $suggest ) {
						$suggestDocs[] = new \Elastica\Document( null, $suggest );
					}
				}
				$self->outputProgress( $docsDumped, $totalDocsToDump );
				Util::withRetry( $retryAttempts,
					function() use ( $destinationType, $suggestDocs ) {
						$destinationType->addDocuments( $suggestDocs );
					}
				);
			}, 0, $retryAttempts );
		$this->output( "Indexing done.\n" );
	}

	public function validateAlias() {
		// @todo utilize the following once Elastica is updated to support passing
		// master_timeout. This is a copy of the Elastica\Index::addAlias() method
		// $this->getIndex()->addAlias( $this->getConnection()->getIndexName( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE_NAME ), true );
		$index = $this->getIndex();
		$name = $this->getConnection()->getIndexName( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE_NAME );

		$path = '_aliases';
		$data = array('actions' => array());
		$status = new Status($index->getClient());
		foreach ($status->getIndicesWithAlias($name) as $aliased) {
			$data['actions'][] = array('remove' => array('index' => $aliased->getName(), 'alias' => $name));
		}

		$data['actions'][] = array('add' => array('index' => $index->getName(), 'alias' => $name));

		return $index->getClient()->request($path, Request::POST, $data, array( 'master_timeout' => $this->masterTimeout ) );
	}

	/**
	 * public because php 5.3 does not support accessing private
	 * methods in a closure.
	 */
	public function outputProgress( $docsDumped, $limit ) {
		if ( $docsDumped <= 0 ) {
			return;
		}
		$pctDone = (int) ( ( $docsDumped / $limit ) * 100 );
		if ( $this->lastProgressPrinted == $pctDone ) {
			return;
		}
		$this->lastProgressPrinted = $pctDone;
		if ( ( $pctDone % 2 ) == 0 ) {
			$this->outputIndented( "\t$pctDone% done...\n" );
		}
	}

	/**
	 * @param string $langCode
	 * @param array $availablePlugins
	 * @return AnalysisConfigBuilder
	 */
	private function pickAnalyzer( $langCode, array $availablePlugins = array() ) {
		$analysisConfigBuilder = new \CirrusSearch\Maintenance\SuggesterAnalysisConfigBuilder( $langCode, $availablePlugins );
		$this->outputIndented( 'Picking analyzer...' .
			$analysisConfigBuilder->getDefaultTextAnalyzerType() . "\n" );
		return $analysisConfigBuilder;
	}

	private function createIndex() {
		$maxShardsPerNode = $this->maxShardsPerNode === 'unlimited' ? -1 : $this->maxShardsPerNode;
		// This is "create only" for now.
		if ( $this->getIndex()->exists() ) {
			throw new \Exception( "Index already exists." );
		}

		$mappingConfigBuilder = new SuggesterMappingConfigBuilder();

		// We create the index with 0 replicas, this is faster and will
		// stress less nodes with 4 shards and 2 replicas we would
		// stress 12 nodes (moreover with the optimize flag)
		$settings = array(
			'number_of_shards' => $this->getShardCount(),
			// hacky but we still use auto_expand_replicas
			// for convenience on small install.
			'auto_expand_replicas' => "0-0",
			'analysis' => $this->analysisConfigBuilder->buildConfig(),
			'routing.allocation.total_shards_per_node' => $maxShardsPerNode,
		);

		if ( $this->hasOption( 'allocationIncludeTag' ) ) {
			$this->output( "Using routing.allocation.include.tag: {$this->getOption( 'allocationIncludeTag' )}, " .
				"the index might be stuck in red if the cluster is not properly configured.\n" );
			$settings['routing.allocation.include.tag'] = $this->getOption( 'allocationIncludeTag' );
		}

		if ( $this->hasOption( 'allocationExcludeTag' ) ) {
			$this->output( "Using routing.allocation.exclude.tag: {$this->getOption( 'allocationExcludeTag' )}, " .
				"the index might be stuck in red if the cluster is not properly configured.\n" );
			$settings['routing.allocation.exclude.tag'] = $this->getOption( 'allocationExcludeTag' );
		}

		$args = array(
			'settings' => $settings,
			'mappings' => $mappingConfigBuilder->buildConfig()
		);
		// @todo utilize $this->getIndex()->create(...) once it supports setting
		// the master_timeout parameter.
		$this->getIndex()->request(
			'',
			Request::PUT,
			$args,
			array( 'master_timeout' => $this->masterTimeout )
		);

		// Index create is async, we have to make sure that the index is ready
		// before sending any docs to it.
		$this->waitForGreen();
	}

	private function enableReplicas() {
		$this->output("Enabling replicas...\n");
		$args = array(
			'index' => array(
				'auto_expand_replicas' => $this->getReplicaCount(),
			),
		);

		$path = $this->getIndex()->getName() . "/_settings";
		$this->getIndex()->getClient()->request(
			$path,
			Request::PUT,
			$args,
			array( 'master_timeout' => $this->masterTimeout )
		);

		// The previous call seems to be async, let's wait few sec
		// otherwise replication won't have time to start.
		sleep(20);

		// Index will be yellow while replica shards are being allocated.
		$this->waitForGreen( $this->getOption( 'replicationTimeout', 3600 ) );
	}

	private function waitForGreen( $timeout = 600 ) {
		$this->output( "Waiting for the index to go green...\n" );
		// Wait for the index to go green ( default 10 min)
		if ( !$this->utils->waitForGreen( $this->getIndex()->getName(), $timeout ) ) {
			$this->error( "Failed to wait for green... please check config and delete the {$this->getIndex()->getName()} index if it was created.", 1 );
		}
	}

	private function getReplicaCount() {
		return $this->getConnection()->getSettings()->getReplicaCount( $this->indexTypeName );
	}

	private function getShardCount() {
		return $this->getConnection()->getSettings()->getShardCount( $this->indexTypeName );
	}

	private function updateVersions() {
		$this->outputIndented( "Updating tracking indexes..." );
		$index = $this->getConnection()->getIndex( 'mw_cirrus_versions' );
		if ( !$index->exists() ) {
			throw new \Exception("mw_cirrus_versions does not exist, you must index your data first");
		}
		list( $aMaj, $aMin ) = explode( '.', \CirrusSearch\Maintenance\SuggesterAnalysisConfigBuilder::VERSION );
		list( $mMaj, $mMin ) = explode( '.', \CirrusSearch\Maintenance\SuggesterMappingConfigBuilder::VERSION );
		$doc = new \Elastica\Document(
			$this->getConnection()->getIndexName( $this->indexBaseName, $this->indexTypeName ),
			array (
				'analysis_maj' => $aMaj,
				'analysis_min' => $aMin,
				'mapping_maj' => $mMaj,
				'mapping_min' => $mMin,
				'shard_count' => $this->getShardCount(),
			)
		);
		$index->getType('version')->addDocument( $doc );
		$this->output("ok.\n");
	}

	/**
	 * @return \CirrusSearch\Maintenance\Validators\Validator[]
	 */
	private function getIndexSettingsValidators() {
		$validators = array();
		$validators[] = new \CirrusSearch\Maintenance\Validators\NumberOfShardsValidator( $this->getIndex(), $this->getShardCount(), $this );
		$validators[] = new \CirrusSearch\Maintenance\Validators\ReplicaRangeValidator( $this->getIndex(), $this->getReplicaCount(), $this );
		$validators[] = $this->getShardAllocationValidator();
		$validators[] = new \CirrusSearch\Maintenance\Validators\MaxShardsPerNodeValidator( $this->getIndex(), $this->indexTypeName, $this->maxShardsPerNode, $this );
		return $validators;
	}

	/**
	 * @return \Elastica\Index being updated
	 */
	public function getIndex() {
		return $this->getConnection()->getIndex( $this->indexBaseName, $this->indexTypeName, $this->indexIdentifier );
	}

	public function getType() {
		return $this->getIndex()->getType( Connection::TITLE_SUGGEST_TYPE_NAME );
	}

	/**
	 * @return Elastica\Client
	 */
	protected function getClient() {
		return $this->getConnection()->getClient();
	}

	/**
	 * @return string name of the index type being updated
	 */
	protected function getIndexTypeName() {
		return $this->getConnection()->getIndexName( $this->indexBaseName, $this->indexTypeName );
	}
}

$maintClass = 'CirrusSearch\Maintenance\UpdateSuggesterIndex';
require_once RUN_MAINTENANCE_IF_MAIN;
