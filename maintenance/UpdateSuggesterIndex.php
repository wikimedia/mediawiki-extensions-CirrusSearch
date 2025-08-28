<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use CirrusSearch\Connection;
use CirrusSearch\Elastica\SearchAfter;
use CirrusSearch\ElasticaErrorHandler;
use CirrusSearch\Maintenance\Validators\AnalyzersValidator;
use CirrusSearch\SearchConfig;
use Elastica;
use Elastica\Query;
use Elastica\Request;
use RuntimeException;

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

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';
require_once __DIR__ . '/../includes/Maintenance/ProgressPrinter.php';
// @codeCoverageIgnoreEnd

class UpdateSuggesterIndex extends Maintenance {
	use ProgressPrinter;

	private const SUFFIX = Connection::TITLE_SUGGEST_INDEX_SUFFIX;

	/**
	 * @var string language code we're building for
	 */
	private $langCode;

	/**
	 * @var int
	 */
	private $indexChunkSize;

	/**
	 * @var int
	 */
	private $indexRetryAttempts;

	/**
	 * @var string
	 */
	private $indexBaseName;

	/**
	 * @var bool optimize the index when done.
	 */
	private $optimizeIndex;

	/**
	 * @var array list of available plugins
	 */
	private $availablePlugins;

	/**
	 * @var string
	 */
	private string $masterTimeout;

	/**
	 * @var ConfigUtils
	 */
	private $utils;

	/**
	 * @var string[]
	 */
	private $bannedPlugins;

	/**
	 * @var CompletionSuggesterIndexer[]
	 */
	private array $indexers;

	/**
	 * @var bool force the promotion of the new index even it seems wrong.
	 */
	private bool $force = false;
	private ?string $allocationIncludeTag;
	private ?string $allocationExcludeTag;
	private int $replicationTimeout;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Create a new suggester index. Always operates on a single cluster." );
		$this->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
		$this->addOption( 'indexChunkSize', 'Documents per shard to index in a batch.   ' .
			'Note when changing the number of shards that the old shard size is used, not the new ' .
			'one.  If you see many errors submitting documents in bulk but the automatic retry as ' .
			'singles works then lower this number.  Defaults to 500.', false, true );
		$this->addOption( 'indexRetryAttempts', 'Number of times to back off and retry ' .
			'per failure.  Note that failures are not common but if Elasticsearch is in the process ' .
			'of moving a shard this can time out.  This will retry the attempt after some backoff ' .
			'rather than failing the whole reindex process.  Defaults to 5.', false, true );
		$this->addOption( 'optimize',
			'Optimize the index to 1 segment. Defaults to false.', false, false );
		$this->addOption( 'scoringMethod',
			'The scoring method to use when computing suggestion weights. ' .
			'Defaults to $wgCirrusSearchCompletionDefaultScore or quality if unset.', false, true );
		$this->addOption( 'masterTimeout',
			'The amount of time to wait for the master to respond to mapping ' .
			'updates before failing. Defaults to $wgCirrusSearchMasterTimeout.', false, true );
		$this->addOption( 'replicationTimeout',
			'The amount of time (seconds) to wait for the replica shards to initialize. ' .
			'Defaults to 3600 seconds.', false, true );
		$this->addOption( 'allocationIncludeTag',
			'Set index.routing.allocation.include.tag on the created index. Useful if you want to ' .
			'force the suggester index not to be allocated on a specific set of nodes.',
			false, true );
		$this->addOption( 'allocationExcludeTag',
			'Set index.routing.allocation.exclude.tag on the created index. Useful if you want ' .
			'to force the suggester index not to be allocated on a specific set of nodes.',
			false, true );
		$this->addOption( 'force', 'Force promoting the new index even if statistics suggest that a problem occurred',
			false, false );
		$this->addOption( 'recreate', "Force the creation of a new index." );
	}

	/** @inheritDoc */
	public function execute() {
		global $wgCirrusSearchMasterTimeout;

		$this->disablePoolCountersAndLogging();
		$this->workAroundBrokenMessageCache();
		$this->masterTimeout = $this->getOption( 'masterTimeout', $wgCirrusSearchMasterTimeout );

		$useCompletion = $this->getSearchConfig()->get( 'CirrusSearchUseCompletionSuggester' );

		if ( $useCompletion !== 'build' && $useCompletion !== 'yes' && $useCompletion !== true ) {
			$this->fatalError( "Completion suggester disabled, quitting..." );
		}

		$cluster = $this->decideCluster();

		if ( $cluster === null ) {
			$this->fatalError( "Cluster not specified, aborting..." );
		}
		$this->log( "Using cluster $cluster\n" );

		$this->indexBaseName = $this->getOption(
			'baseName', $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME )
		);
		$this->indexChunkSize = $this->getOption( 'indexChunkSize', 500 );
		$this->indexRetryAttempts = $this->getOption( 'reindexRetryAttempts', 5 );
		$replicationTimeout = $this->getOption( 'replicationTimeout', 3600 );
		if ( !ctype_digit( (string)$replicationTimeout ) ) {
			$this->fatalError( "--replicationTimeout timeout must be a positive integer" );
		}
		$this->replicationTimeout = (int)$replicationTimeout;

		$this->optimizeIndex = $this->getOption( 'optimize', false );
		$this->allocationIncludeTag = $this->getOption( 'allocationIncludeTag' );
		$this->allocationExcludeTag = $this->getOption( 'allocationExcludeTag' );

		$this->force = $this->getOption( 'force', false );

		$this->utils = new ConfigUtils( $this->getClient(), $this );

		try {
			$this->requireCirrusReady();
			$this->checkAndDeleteBrokenIndices();
			$this->indexers = [ $this->buildIndexer( $cluster, $this->indexBaseName, $this->getSearchConfig() ) ];
			foreach ( $this->indexers as $indexer ) {
				$indexer->prepare();
			}
			$this->indexData();
			foreach ( $this->indexers as $indexer ) {
				$indexer->finish();
			}
		} catch ( \Elastica\Exception\Connection\HttpException $e ) {
			$message = $e->getMessage();
			$this->log( "\nUnexpected Elasticsearch failure.\n" );
			$this->fatalError( "Http error communicating with Elasticsearch:  $message.\n" );
		} catch ( IndexPromotionException $indexPromotionException ) {
			$this->log( "Failed to promote one or more completion indices " );
			$this->fatalError( $indexPromotionException->getMessage() );
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$type = get_class( $e );
			$message = ElasticaErrorHandler::extractMessage( $e );
			$trace = $e->getTraceAsString();
			$this->log( "\nUnexpected Elasticsearch failure.\n" );
			$this->fatalError( "Elasticsearch failed in an unexpected way.  " .
				"This is always a bug in CirrusSearch.\n" .
				"Error type: $type\n" .
				"Message: $message\n" .
				"Trace:\n" . $trace );
		}

		return true;
	}

	private function getSourceIndexes(): array {
		// We build the suggestions by reading CONTENT and GENERAL indices.
		// This does not support extra indices like FILES on commons.
		$sourceIndexSuffixes = [
			Connection::CONTENT_INDEX_SUFFIX,
			Connection::GENERAL_INDEX_SUFFIX
		];
		$sourceIndexes = [];
		foreach ( $sourceIndexSuffixes as $sourceIndexSuffix ) {
			$sourceIndexes[$sourceIndexSuffix] = $this->getConnection()
				->getIndex( $this->indexBaseName, $sourceIndexSuffix );
		}
		return $sourceIndexes;
	}

	private function buildIndexer(
		string $cluster,
		string $indexBaseName,
		SearchConfig $config,
		bool $altIndex = false,
		int $altIndexId = 0
	): CompletionSuggesterIndexer {
		$connection = new Connection( $config, $cluster );
		$utils = new ConfigUtils( $connection->getClient(), $this );

		$shardCount = $connection->getSettings()->getShardCount( self::SUFFIX );
		$replicaCount = $connection->getSettings()->getReplicaCount( self::SUFFIX );
		$maxShardPerNode = $connection->getSettings()->getMaxShardsPerNode( self::SUFFIX );

		$this->unwrap( $this->utils->checkElasticsearchVersion() );

		$indexAliasName = $connection->getIndexName( $indexBaseName, self::SUFFIX, false, $altIndex, $altIndexId );
		$bannedPlugins = $config->get( 'CirrusSearchBannedPlugins' );

		$availablePlugins = $this->unwrap( $utils->scanAvailablePlugins( $bannedPlugins ) );
		$analysisConfigBuilder = new SuggesterAnalysisConfigBuilder( $connection->getConfig()->get( 'LanguageCode' ),
			$availablePlugins, $config );
		$analysisConfig = $analysisConfigBuilder->buildConfig();
		$recycle = $this->canRecycle( $connection, $analysisConfig, $altIndex, $altIndexId );
		$oldIndexIdentifier = $this->unwrap( $utils->pickIndexIdentifierFromOption(
			'current', $indexAliasName
		) );
		$currentIndex = $connection->getIndex(
			$this->indexBaseName, self::SUFFIX, $oldIndexIdentifier, $altIndex, $altIndexId
		);

		$oldIndex = null;

		if ( !$currentIndex->exists() ) {
			$targetIndex = $currentIndex; // most probably the '_first' index
		} elseif ( $recycle ) {
			$targetIndex = $currentIndex;
		} else {
			$oldIndex = $currentIndex;
			$targetIndex = $connection->getIndex(
				$this->indexBaseName,
				self::SUFFIX,
				$this->unwrap( $utils->pickIndexIdentifierFromOption(
					'now', $indexAliasName
				) ),
				$altIndex,
				$altIndexId
			);
		}
		$builder = SuggestBuilder::create( $connection,
			$this->getOption( 'scoringMethod' ), $this->indexBaseName );
		$indexerConfig = new CompletionSuggesterIndexerConfig(
			$this->indexBaseName,
			$altIndex,
			$altIndexId,
			$shardCount,
			$replicaCount,
			$maxShardPerNode,
			$recycle,
			$this->indexChunkSize,
			$this->masterTimeout,
			$this->replicationTimeout,
			$this->indexRetryAttempts,
			$this->allocationIncludeTag,
			$this->allocationExcludeTag,
			$this->optimizeIndex,
			$this->force
		);
		return new CompletionSuggesterIndexer(
			$connection,
			$targetIndex,
			$oldIndex,
			$builder,
			$this,
			$utils,
			$this->getMetaStore( $connection )->versionStore(),
			$analysisConfigBuilder,
			$indexerConfig
		);
	}

	protected function requireCirrusReady() {
		parent::requireCirrusReady();

		foreach ( $this->getSourceIndexes() as $suffix => $index ) {
			if ( !$index->exists() ) {
				throw new RuntimeException( "Missing source index: {$index->getName()}" );
			}
		}
	}

	private function workAroundBrokenMessageCache() {
		// Under some configurations (T288233) the i18n cache fails to
		// initialize. After failing, at least in this particular deployment,
		// it will fallback to local CDB files and ignore on-wiki overrides
		// which is acceptable for this script.
		try {
			wfMessage( 'ok' )->text();
		} catch ( \LogicException ) {
			// The first failure should trigger the fallback mode, this second
			// try should work (and not throw the LogicException deep in the updates).
			wfMessage( 'ok' )->text();
		}
	}

	/**
	 * Check for duplicate indices that may have been created
	 * by a previous update that failed.
	 */
	private function checkAndDeleteBrokenIndices() {
		$indices = $this->unwrap(
			$this->utils->getAllIndicesByType( $this->getConnection()->getIndexName( $this->indexBaseName, self::SUFFIX ) )
		);
		foreach ( $indices as $indexName ) {
			$status = $this->utils->isIndexLive( $indexName );
			if ( !$status->isGood() ) {
				$this->log( (string)$status );
			} elseif ( $status->getValue() === false ) {
				$this->log( "Deleting broken index {$indexName}\n" );
				$this->deleteIndex( $this->getConnection()->getIndex( $indexName ) );
			}
		}
		# If something went wrong the process will fail when calling pickIndexIdentifierFromOption
	}

	private function canRecycle( Connection $connection, array $analysisConfig, bool $altIndex, int $altIndexId = 0 ): bool {
		global $wgCirrusSearchRecycleCompletionSuggesterIndex;
		if ( !$wgCirrusSearchRecycleCompletionSuggesterIndex ) {
			return false;
		}

		if ( $this->getOption( "recreate", false ) ) {
			return false;
		}

		$indexAliasName = $connection->getIndexName( $this->indexBaseName, self::SUFFIX, false, $altIndex, $altIndexId );
		$utils = new ConfigUtils( $connection->getClient(), $this );
		$oldIndexIdentifier = $this->unwrap( $utils->pickIndexIdentifierFromOption(
			'current', $indexAliasName
		) );
		$oldIndex = $this->getConnection()->getIndex(
			$this->indexBaseName, self::SUFFIX, $oldIndexIdentifier, $altIndex, $altIndexId
		);
		if ( !$oldIndex->exists() ) {
			$this->error( "Index {$oldIndex->getName()} does not exist yet cannot recycle." );
			return false;
		}
		$currentIndexAlias = $connection->getIndex(
			$this->indexBaseName, self::SUFFIX
		);
		if ( !$currentIndexAlias->exists() ) {
			$this->error( "Index {$currentIndexAlias->getName()} has no active alias? cannot recycle." );
			return false;
		}
		$refresh = $oldIndex->getSettings()->getRefreshInterval();
		if ( $refresh != '-1' ) {
			$this->error( "Refresh interval on {$oldIndex->getName()} is not -1, cannot recycle." );
			return false;
		}

		$shards = $oldIndex->getSettings()->get( 'number_of_shards' );
		// We check only the number of shards since it cannot be updated.
		if ( $shards != $connection->getSettings()->getShardCount( self::SUFFIX ) ) {
			$this->error( "{$oldIndex->getName()} number of shards mismatch cannot recycle." );
			return false;
		}

		$mMaj = explode( '.', SuggesterMappingConfigBuilder::VERSION, 2 )[0];
		$aMaj = explode( '.', SuggesterAnalysisConfigBuilder::VERSION, 2 )[0];

		try {
			$versionDoc = $this->getMetaStore( $connection )
				->versionStore()
				->find( $this->indexBaseName, self::SUFFIX, $altIndex, $altIndexId );
		} catch ( \Elastica\Exception\NotFoundException ) {
			$this->error( "Index $indexAliasName missing in mw_cirrus_metastore::version, cannot recycle." );
			return false;
		}

		if ( $versionDoc->analysis_maj != $aMaj ) {
			$this->error( 'Analysis config version mismatch, cannot recycle.' );
			return false;
		}

		if ( $versionDoc->mapping_maj != $mMaj ) {
			$this->error( 'Mapping config version mismatch, cannot recycle.' );
			return false;
		}

		$validator = new AnalyzersValidator( $oldIndex, $analysisConfig, $this );
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->error( "Analysis config on $indexAliasName differs, cannot recycle." );
			return false;
		}

		return true;
	}

	/**
	 * Delete an index
	 */
	private function deleteIndex( \Elastica\Index $index ) {
		// @todo Utilize $this->oldIndex->delete(...) once Elastica library is updated
		// to allow passing the master_timeout
		$index->request(
			'',
			Request::DELETE,
			[],
			[ 'master_timeout' => $this->masterTimeout ]
		);
	}

	private function indexData() {
		$query = new Query();
		$fields = [];
		foreach ( $this->indexers as $indexer ) {
			$fields = array_merge( $fields, $indexer->getRequiredFields() );
		}
		$query->setSource( [
			'includes' => array_unique( $fields )
		] );

		$pageAndNs = new Elastica\Query\BoolQuery();
		$pageAndNs->addShould( new Elastica\Query\Term( [ "namespace" => NS_MAIN ] ) );
		$pageAndNs->addShould( new Elastica\Query\Term( [ "redirect.namespace" => NS_MAIN ] ) );
		$bool = new Elastica\Query\BoolQuery();
		$bool->addFilter( $pageAndNs );

		$query->setQuery( $bool );
		$query->setSort( [
			[ 'page_id' => 'asc' ],
		] );
		// Explicitly ask for accurate total_hits even-though we use a scroll request
		$query->setTrackTotalHits( true );

		$totalDocsDumpedFromAllIndices = 0;
		$totalHitsFromAllIndices = 0;
		foreach ( $this->getSourceIndexes() as $sourceIndexSuffix => $sourceIndex ) {
			$search = new \Elastica\Search( $this->getClient() );
			$search->setQuery( $query );
			$search->addIndex( $sourceIndex );
			$query->setSize( $this->indexChunkSize );
			$totalDocsToDump = -1;
			$searchAfter = new SearchAfter( $search );

			$docsDumped = 0;

			foreach ( $searchAfter as $results ) {
				if ( $totalDocsToDump === -1 ) {
					$totalDocsToDump = $results->getTotalHits();
					$totalHitsFromAllIndices += $totalDocsToDump;
					$this->log( "total hits: $totalDocsToDump\n" );
					if ( $totalDocsToDump === 0 ) {
						$this->log( "No documents to index from $sourceIndexSuffix\n" );
						break;
					}
					foreach ( $this->indexers as $indexer ) {
						$this->log( "Indexing $totalDocsToDump documents from $sourceIndexSuffix to " .
									"{$indexer->getTargetIndex()->getName()} with batchId: {$indexer->getBatchId()}\n" );
					}
				}
				$inputDocs = [];
				foreach ( $results as $result ) {
					$docsDumped++;
					$inputDocs[] = [
						'id' => $result->getId(),
						'source' => $result->getSource()
					];
				}
				foreach ( $this->indexers as $indexer ) {
					$indexer->addDocument( $inputDocs );
				}

				$this->outputProgress( $docsDumped, $totalDocsToDump );
			}
			foreach ( $this->indexers as $indexer ) {
				$indexer->flushSuggestDocs();
			}

			$this->log( "Indexing from $sourceIndexSuffix index done.\n" );
			$totalDocsDumpedFromAllIndices += $docsDumped;
		}
		$this->log( "Exported $totalDocsDumpedFromAllIndices ($totalHitsFromAllIndices total hits) " .
					"from the search indices.\n" );
		foreach ( $this->indexers as $indexer ) {
			$this->log( $indexer->formatIndexingStats() );
		}
	}

	/**
	 * @param string $message
	 * @param string|null $channel
	 */
	public function log( $message, $channel = null ) {
		$date = new \DateTime();
		parent::output( $date->format( 'Y-m-d H:i:s' ) . " " . $message, $channel );
	}

	/**
	 * @return Elastica\Client
	 */
	protected function getClient() {
		return $this->getConnection()->getClient();
	}

}

// @codeCoverageIgnoreStart
$maintClass = UpdateSuggesterIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
