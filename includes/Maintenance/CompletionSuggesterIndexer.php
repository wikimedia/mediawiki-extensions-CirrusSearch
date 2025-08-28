<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use CirrusSearch\Connection;
use CirrusSearch\Elastica\SearchAfter;
use CirrusSearch\MetaStore\MetaVersionStore;
use DateTime;
use Elastica\Client;
use Elastica\Document;
use Elastica\Index;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Elastica\Request;
use Elastica\Search;
use Elastica\Status;
use MWElasticUtils;
use RuntimeException;
use StatusValue;

/**
 * CompletionSuggesterIndexer is responsible for populating a completion suggester using
 * source data pulled by the UpdaterSuggesterIndex maint script.
 * The process is as follow:
 * - construct the CompletionSuggesterIndexer based on the state of the cluster, settings and
 *   script options
 * - call {@see CompletionSuggesterIndexer::prepare()}
 * - loop over the source documents and call {@see CompletionSuggesterIndexer::addDocument()}
 * - flush any remaining buffered doc via {@see CompletionSuggesterIndexer::flushSuggestDocs()}
 * - finally call {@see CompletionSuggesterIndexer::finish()}
 *
 * To promote and/or cleanup the target index.
 */
class CompletionSuggesterIndexer {
	use ProgressPrinter;

	/**
	 * @var int perform extra checks prior to validating the new index if the number of docs is above this threshold.
	 *  For context these extra checks are added in an attempt to understand what's causing T363521.
	 */
	private const EXTRA_CHECK_THRESHOLD = 100000;

	/**
	 * Max deviation between the number of suggestion we build and the count on the resulting index.
	 */
	private const BUILT_VS_INDEXED_DOCS_MAX_DEVIATION = 0.01;

	/**
	 * Max deviation between the new and old index.
	 */
	private const PREVIOUS_VS_NEXT_COUNT_MAX_DEVIATION = 0.1;

	private Connection $connection;
	private Index $index;
	private ?Index $oldIndex;
	private SuggestBuilder $suggestBuilder;
	private Printer $output;
	private ConfigUtils $utils;
	private MetaVersionStore $versionStore;
	private SuggesterAnalysisConfigBuilder $analysisConfigBuilder;
	private CompletionSuggesterIndexerConfig $indexerConfig;

	/**
	 * @var Document[]
	 */
	private array $batch = [];

	/**
	 * @var array
	 */
	private array $indexingStats = [
		'doc_built' => 0,
		'doc_indexed' => 0,
		'bulk_requests' => 0,
		'retried_bulk_requests' => 0,
		'doc_sent' => 0,
		'index_results' => [
			'created' => 0,
			'updated' => 0,
			'noop' => 0,
			'unknown' => 0,
			'error' => 0,
		],
	];

	/**
	 * @param Connection $connection the connection to work on
	 * @param Index $index the target index
	 * @param Index|null $oldIndex the optional old index, must be null in case of recycle, must be the live index when rebuilding
	 * @param SuggestBuilder $suggestBuilder the SuggestBuilder to build suggest docs
	 * @param Printer $output the output to print message and errors
	 * @param ConfigUtils $utils the config utils
	 * @param MetaVersionStore $versionStore the version store attached to the right cluster
	 * @param SuggesterAnalysisConfigBuilder $analysisConfigBuilder the builder to create the analysis settings
	 * @param CompletionSuggesterIndexerConfig $indexerConfig the various settings used by this indexer
	 */
	public function __construct(
		Connection $connection,
		Index $index, ?Index $oldIndex,
		SuggestBuilder $suggestBuilder,
		Printer $output,
		ConfigUtils $utils,
		MetaVersionStore $versionStore,
		SuggesterAnalysisConfigBuilder $analysisConfigBuilder,
		CompletionSuggesterIndexerConfig $indexerConfig
	) {
		$this->connection = $connection;
		$this->index = $index;
		$this->oldIndex = $oldIndex;
		$this->suggestBuilder = $suggestBuilder;
		$this->output = $output;
		$this->utils = $utils;
		$this->versionStore = $versionStore;
		$this->analysisConfigBuilder = $analysisConfigBuilder;
		$this->indexerConfig = $indexerConfig;
	}

	public function prepare(): void {
		if ( !$this->indexerConfig->isRecycle() ) {
			$this->createIndex();
		}
	}

	public function addDocument( array $inputDocs ): void {
		$totalSuggestDocsIndexed = 0;
		foreach ( $this->suggestBuilder->build( $inputDocs ) as $doc ) {
			$this->batch[] = $doc;
			$totalSuggestDocsIndexed++;
			if ( count( $this->batch ) >= $this->indexerConfig->getIndexChunkSize() ) {
				$this->flushSuggestDocs();
			}
		}
		$this->indexingStats['doc_built'] += $totalSuggestDocsIndexed;
	}

	public function flushSuggestDocs(): void {
		if ( $this->batch === [] ) {
			return;
		}
		$attemptedAtLeastOnce = false;
		MWElasticUtils::withRetry( $this->indexerConfig->getIndexRetryAttempts(),
			function () use ( &$attemptedAtLeastOnce ) {
				$this->indexingStats['bulk_requests']++;
				$this->indexingStats['doc_sent'] += count( $this->batch );
				if ( $attemptedAtLeastOnce ) {
					$this->indexingStats['retried_bulk_requests']++;
				}
				$attemptedAtLeastOnce = true;
				$response = $this->index->addDocuments( $this->batch );
				$allowedOps = [ 'created', 'updated', 'noop' ];
				foreach ( $response->getBulkResponses() as $r ) {
					if ( $r->hasError() ) {
						if ( $this->indexingStats['index_results']['error'] < 10000 ) {
							// do not spam the logs unnecessarily if we encountered 10000 errors.
							// Hopefully 10000 is already enough to understand what's happening.
							$this->output->error( "Failed to index doc {$r->getData()["_id"]} with {$r->getError()}" );
						}
						$this->indexingStats['index_results']['error']++;
						continue;
					}
					$opRes = 'unknown';
					if ( isset( $r->getData()["result"] ) ) {
						$res = $r->getData()["result"];
						if ( in_array( $res, $allowedOps ) ) {
							$opRes = $res;
						}
						$this->indexingStats['index_results'][$opRes]++;
					}
				}
			}
		);
		$this->batch = [];
	}

	public function formatIndexingStats(): string {
		return "Indexing stats for {$this->index->getName()}: bulk requests " .
			"{$this->indexingStats["bulk_requests"]} (retried {$this->indexingStats["retried_bulk_requests"]}), " .
			"{$this->indexingStats["doc_sent"]}/" .
			"{$this->indexingStats["index_results"]["created"]}/" .
			"{$this->indexingStats["index_results"]["updated"]}/" .
			"{$this->indexingStats["index_results"]["noop"]}/" .
			"{$this->indexingStats["index_results"]["error"]} " .
			"(sent/created/updated/noop/error)\n";
	}

	/**
	 * @throws IndexPromotionException
	 */
	public function finish(): void {
		if ( $this->batch !== [] ) {
			throw new \LogicException( "{$this->index->getName()}: write buffer not empty." );
		}
		if ( $this->indexerConfig->isRecycle() ) {
			$this->finishRecycle();
		} else {
			$this->finishAndPromote();
		}
	}

	/**
	 * @throws IndexPromotionException
	 */
	private function finishAndPromote(): void {
		if ( $this->indexerConfig->isOptimizeIndex() ) {
			$this->optimize();
		}
		$docsInIndex = $this->refreshAndWaitForCount();
		$totalSuggestDocs = $this->indexingStats['doc_built'];
		if ( $docsInIndex != $totalSuggestDocs && $totalSuggestDocs > 0 ) {
			$this->output->error( "{$this->index->getName()}: prepared and indexed $totalSuggestDocs docs but the index has $docsInIndex" );
			$errorRatio = ( $totalSuggestDocs - $docsInIndex ) / $totalSuggestDocs;
			if (
				!$this->indexerConfig->isForce() &&
				$totalSuggestDocs > self::EXTRA_CHECK_THRESHOLD &&
				$errorRatio > self::BUILT_VS_INDEXED_DOCS_MAX_DEVIATION
			) {
				throw new IndexPromotionException( "New index {$this->index->getName()}: " .
												   "deviation between docs built vs indexed docs is above " .
												   self::BUILT_VS_INDEXED_DOCS_MAX_DEVIATION );
			}
		}
		$this->enableReplicas();
		$this->validateAlias();
		$this->updateVersions();
		$this->deleteOldIndex();
		$this->log( "Done.\n" );
	}

	private function finishRecycle(): void {
		// This is fragile... hopefully most of the docs will be deleted from the old segments
		// and will result in a fast operation.
		// New segments should not be affected.
		// Unfortunately if a failure causes the process to stop
		// the FST will maybe contains duplicates as it cannot (elastic 1.7)
		// filter deleted docs. We will rely on output deduplication
		// but this will certainly affect performances.

		$this->expungeDeletes();
		// Refresh the reader so we can scroll over remaining docs.
		// At this point we may read the new un-optimized FST segments
		// Old ones should be pretty small after expungeDeletes
		$this->safeRefresh( $this->index );

		$bool = new BoolQuery();
		$bool->addMustNot(
			new Term( [ "batch_id" => $this->suggestBuilder->getBatchId() ] )
		);

		$query = new Query();
		$query->setQuery( $bool );
		$query->setSize( $this->indexerConfig->getIndexChunkSize() );
		$query->setSource( false );
		$query->setSort( [
			[ '_id' => 'asc' ],
		] );
		// Explicitly ask for accurate total_hits even-though we use a scroll request
		$query->setTrackTotalHits( true );
		$search = new Search( $this->getClient() );
		$search->setQuery( $query );
		$search->addIndex( $this->index );
		$searchAfter = new SearchAfter( $search );

		$totalDocsToDump = -1;
		$docsDumped = 0;

		$this->log( "Deleting remaining docs from previous batch\n" );
		foreach ( $searchAfter as $results ) {
			if ( $totalDocsToDump === -1 ) {
				$totalDocsToDump = $results->getTotalHits();
				if ( $totalDocsToDump === 0 ) {
					break;
				}
				$docsDumped = 0;
			}
			$docIds = [];
			foreach ( $results as $result ) {
				$docsDumped++;
				$docIds[] = $result->getId();
			}
			$this->outputProgress( $docsDumped, $totalDocsToDump );
			if ( !$docIds ) {
				continue;
			}

			MWElasticUtils::withRetry( $this->indexerConfig->getIndexRetryAttempts(),
				function () use ( $docIds ) {
					$this->index->deleteByQuery( new Query\Ids( $docIds ) );
				}
			);
		}
		$this->log( "Done.\n" );
		// Old docs should be deleted now we can optimize and flush
		$this->optimize();

		// @todo add support for changing the number of replicas
		// if the setting was changed in cirrus config.
		// Workaround is to change the settings directly on the cluster.

		// Refresh the reader so it now uses the optimized FST,
		// and actually free and delete old segments.
		$this->safeRefresh( $this->index );
		$docsInIndex = $this->safeCount( $this->index );
		if ( $docsInIndex != $this->indexingStats['doc_built'] ) {
			$this->output->error( "{$this->index->getName()}: Prepared and indexed " .
								  "{$this->indexingStats['doc_built']} docs but the index has $docsInIndex" );
		}
	}

	private function createIndex(): void {
		// This is "create only" for now.
		if ( $this->index->exists() ) {
			throw new RuntimeException( "Index {$this->index->getName()} already exists." );
		}

		$mappingConfigBuilder = new SuggesterMappingConfigBuilder( $this->connection->getConfig() );

		// We create the index with 0 replicas, this is faster and will
		// stress less nodes with 4 shards and 2 replicas we would
		// stress 12 nodes (moreover with the optimize flag)
		$settings = [
			'number_of_shards' => $this->indexerConfig->getShardCount(),
			// hacky but we still use auto_expand_replicas
			// for convenience on small install.
			'auto_expand_replicas' => "0-0",
			'refresh_interval' => -1,
			'analysis' => $this->analysisConfigBuilder->buildConfig(),
			'routing.allocation.total_shards_per_node' => $this->indexerConfig->getMaxShardPerNode(),
		];

		if ( $this->indexerConfig->getAllocationIncludeTag() !== null ) {
			$this->output->output( "Using routing.allocation.include.tag: " .
								   "{$this->indexerConfig->getAllocationIncludeTag()}, the index might be stuck in red " .
								   "if the cluster is not properly configured.\n" );
			$settings['routing.allocation.include.tag'] = $this->indexerConfig->getAllocationIncludeTag();
		}

		if ( $this->indexerConfig->getAllocationExcludeTag() ) {
			$this->output->output( "Using routing.allocation.exclude.tag: " .
								   "{$this->indexerConfig->getAllocationExcludeTag()}, the index might be stuck in red " .
								   "if the cluster is not properly configured.\n" );
			$settings['routing.allocation.exclude.tag'] = $this->indexerConfig->getAllocationExcludeTag();
		}

		$args = [
			'settings' => [ 'index' => $settings ],
			'mappings' => $mappingConfigBuilder->buildConfig(),
		];
		$this->index->create(
			$args,
			[ 'master_timeout' => $this->indexerConfig->getMasterTimeout() ]
		);

		// Index create is async, we have to make sure that the index is ready
		// before sending any docs to it.
		$this->waitForGreen( $this->indexerConfig->getReplicationTimeout() );
	}

	private function expungeDeletes(): void {
		$this->log( "Purging deleted docs on {$this->index->getName()}..." );
		$this->index->forcemerge( [ 'only_expunge_deletes' => 'true', 'flush' => 'false' ] );
		$this->output->output( "ok.\n" );
	}

	/**
	 * @param string $message
	 */
	public function log( string $message ): void {
		$date = new DateTime();
		$this->output->output( "{$date->format( 'Y-m-d H:i:s' )} {$this->index->getName()} $message" );
	}

	private function optimize(): void {
		$this->log( "Optimizing index {$this->index->getName()}..." );
		$this->index->forcemerge( [ 'max_num_segments' => 1 ] );
		$this->output->output( "ok.\n" );
	}

	public function validateAlias(): void {
		// @todo utilize the following once Elastica is updated to support passing
		// master_timeout. This is a copy of the Elastica\Index::addAlias() method
		// $this->getIndex()->addAlias( $this->getIndexTypeName(), true );
		$index = $this->index;
		$name = $this->getIndexAliasName();

		$path = '_aliases';
		$data = [ 'actions' => [] ];
		$status = new Status( $index->getClient() );
		foreach ( $status->getIndicesWithAlias( $name ) as $aliased ) {
			$data['actions'][] =
				[ 'remove' => [ 'index' => $aliased->getName(), 'alias' => $name ] ];
		}

		$data['actions'][] = [ 'add' => [ 'index' => $index->getName(), 'alias' => $name ] ];

		$index->getClient()
			->request( $path, Request::POST, $data, [ 'master_timeout' => $this->indexerConfig->getMasterTimeout() ] );
	}

	private function deleteOldIndex(): void {
		if ( $this->oldIndex && $this->oldIndex->exists() ) {
			$this->log( "Deleting " . $this->oldIndex->getName() . " ... " );
			// @todo Utilize $this->oldIndex->delete(...) once Elastica library is updated
			// to allow passing the master_timeout
			$this->oldIndex->getClient()->request(
				$this->oldIndex->getName(),
				Request::DELETE,
				[],
				[ 'master_timeout' => $this->indexerConfig->getMasterTimeout() ]
			);
			$this->output->output( "ok.\n" );
		}
	}

	/**
	 * @return string name of the index type being updated
	 */
	private function getIndexAliasName(): string {
		return $this->connection->getIndexName(
			$this->indexerConfig->getIndexBaseName(),
			Connection::TITLE_SUGGEST_INDEX_SUFFIX,
			false,
			$this->indexerConfig->isAltIndex(),
			$this->indexerConfig->getAltIndexId()
		);
	}

	private function getClient(): Client {
		return $this->connection->getClient();
	}

	/**
	 * @inheritDoc
	 */
	public function outputIndented( $message ): void {
		$this->output->outputIndented( $message );
	}

	private function enableReplicas(): void {
		$this->log( "Enabling replicas...\n" );
		$args = [
			'index' => [
				'auto_expand_replicas' => $this->indexerConfig->getReplicaCount(),
			],
		];

		$path = $this->index->getName() . "/_settings";
		$this->index->getClient()->request(
			$path,
			Request::PUT,
			$args,
			[ 'master_timeout' => $this->indexerConfig->getMasterTimeout() ]
		);

		// The previous call seems to be async, let's wait few sec
		// otherwise replication won't have time to start.
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			sleep( 20 );
		}

		// Index will be yellow while replica shards are being allocated.
		$this->waitForGreen( $this->indexerConfig->getReplicationTimeout() );
	}

	private function updateVersions(): void {
		$this->log( "Updating tracking indexes..." );
		$this->versionStore
			->update(
				$this->indexerConfig->getIndexBaseName(),
				Connection::TITLE_SUGGEST_INDEX_SUFFIX,
				$this->indexerConfig->isAltIndex(),
				$this->indexerConfig->getAltIndexId()
			);
		$this->output->output( "ok.\n" );
	}

	private function waitForGreen( int $timeout = 600 ): void {
		$this->log( "Waiting for the index to go green...\n" );
		// Wait for the index to go green ( default 10 min)
		if ( !$this->utils->waitForGreen( $this->index->getName(), $timeout ) ) {
			throw new RuntimeException( "Failed to wait for green... please check config and " .
										 "delete the {$this->index->getName()} index if it was created." );
		}
	}

	/**
	 * @return int
	 * @throws IndexPromotionException
	 */
	private function refreshAndWaitForCount(): int {
		$this->safeRefresh( $this->index );
		$start = microtime( true );
		$timeoutAfter = !defined( 'MW_PHPUNIT_TEST' ) ? $start + 120 : $start + 2;
		if ( $this->oldIndex && $this->oldIndex->exists() ) {
			$oldCount = $this->safeCount( $this->oldIndex );
			while ( true ) {
				$docsInIndex = $this->safeCount( $this->index );
				$this->log( "Old index had $oldCount docs vs $docsInIndex now.\n" );
				$diffRatio = ( $oldCount - $docsInIndex ) / $oldCount;
				// Check for relatively large (>EXTRA_CHECK_THRESHOLD docs) indices that the new index is not
				// abnormally smaller than the new one.
				// We check only "large" indices to avoid false positives on small indices...
				if ( $oldCount > self::EXTRA_CHECK_THRESHOLD &&
					 $diffRatio > self::PREVIOUS_VS_NEXT_COUNT_MAX_DEVIATION ) {
					if ( microtime( true ) > $timeoutAfter ) {
						if ( !$this->indexerConfig->isForce() ) {
							throw new IndexPromotionException( "New index seems too small compared to the previous index " .
											   "$oldCount/$docsInIndex > " .
											   self::PREVIOUS_VS_NEXT_COUNT_MAX_DEVIATION .
											   " (old/new > threshold). Aborting. (Use --force to bypass)" );
						}
					} else {
						$this->log( "Waiting to re-check counts...\n" );
						if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
							sleep( 10 );
						}
					}
				} else {
					return $docsInIndex;
				}
			}
		} else {
			$docsInIndex = $this->safeCount( $this->index );
		}

		return $docsInIndex;
	}

	/**
	 * @param Index $index
	 * @param int $attempts
	 * @return int
	 * @throws RuntimeException
	 */
	private function safeCount( Index $index, int $attempts = 3 ): int {
		return ConfigUtils::safeCountOrFail(
			$index,
			static function ( StatusValue $error ): never {
				throw new RuntimeException( (string)$error );
			},
			$attempts
		);
	}

	/**
	 * @param Index $index
	 * @param int $attempts
	 * @return void
	 * @throws RuntimeException
	 */
	private function safeRefresh( Index $index, int $attempts = 3 ): void {
		ConfigUtils::safeRefreshOrFail(
			$index,
			static function ( StatusValue $error ): never {
				throw new RuntimeException( (string)$error );
			},
			$attempts
		);
	}

	/**
	 * @return int the batchId the indexer is using to mark its indexed docs
	 */
	public function getBatchId(): int {
		return $this->suggestBuilder->getBatchId();
	}

	/**
	 * @return Index the index this indexer is writing to
	 */
	public function getTargetIndex(): Index {
		return $this->index;
	}

	/**
	 * @return string[]
	 */
	public function getRequiredFields(): array {
		return $this->suggestBuilder->getRequiredFields();
	}

	/**
	 * Visible for testing.
	 * @return array
	 */
	public function getIndexingStats(): array {
		return $this->indexingStats;
	}
}
