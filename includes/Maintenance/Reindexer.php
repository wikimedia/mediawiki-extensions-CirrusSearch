<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\SearchConfig;
use CirrusSearch\Util;
use Elastica\Document;
use Elastica\Exception\Connection\HttpException;
use Elastica\Exception\ExceptionInterface;
use Elastica\Index;
use Elastica\Query;
use Elastica\Type;
use ForkController;
use MediaWiki\Logger\LoggerFactory;
use MWElasticUtils;

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
class Reindexer {
	/**
	 * @var SearchConfig
	 */
	private $searchConfig;

	/*** "From" portion ***/
	/**
	 * @var Index
	 */
	private $oldIndex;

	/**
	 * @var Connection
	 */
	private $oldConnection;

	/*** "To" portion ***/

	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var Type[]
	 */
	private $types;

	/**
	 * @var Type[]
	 */
	private $oldTypes;

	/**
	 * @var int
	 */
	private $shardCount;

	/**
	 * @var string
	 */
	private $replicaCount;

	/**
	 * @var array
	 */
	private $mergeSettings;

	/**
	 * @var array
	 */
	private $mappingConfig;

	/**
	 * @var Maintenance
	 */
	private $out;

	/**
	 * @param SearchConfig $searchConfig
	 * @param Connection $source
	 * @param Connection $target
	 * @param Type[] $types
	 * @param Type[] $oldTypes
	 * @param int $shardCount
	 * @param string $replicaCount
	 * @param array $mergeSettings
	 * @param array $mappingConfig
	 * @param Maintenance $out
	 * @throws \Exception
	 */
	public function __construct( SearchConfig $searchConfig, Connection $source, Connection $target, array $types, array $oldTypes, $shardCount, $replicaCount, array $mergeSettings, array $mappingConfig, Maintenance $out = null ) {
		// @todo: this constructor has too many arguments - refactor!
		$this->searchConfig = $searchConfig;
		$this->oldConnection = $source;
		$this->connection = $target;
		$this->types = $types;
		$this->oldTypes = $oldTypes;
		$this->shardCount = $shardCount;
		$this->replicaCount = $replicaCount;
		$this->mergeSettings = $mergeSettings;
		$this->mappingConfig = $mappingConfig;
		$this->out = $out;

		if ( empty($types) || empty($oldTypes) ) {
			throw new \Exception( "Types list should be non-empty" );
		}
		$this->index = $types[0]->getIndex();
		$this->oldIndex = $oldTypes[0]->getIndex();
	}

	/**
	 * Dump everything from the live index into the one being worked on.
	 *
	 * @param int $processes
	 * @param int $refreshInterval
	 * @param int $retryAttempts
	 * @param int $chunkSize
	 * @param float $acceptableCountDeviation
	 */
	public function reindex( $processes = 1, $refreshInterval = 1, $retryAttempts = 5, $chunkSize = 100, $acceptableCountDeviation = .05 ) {
		global $wgCirrusSearchWikimediaExtraPlugin;

		// Set some settings that should help io load during bulk indexing.  We'll have to
		// optimize after this to consolidate down to a proper number of segments but that is
		// is worth the price.  total_shards_per_node will help to make sure that each shard
		// has as few neighbors as possible.
		$this->setConnectionTimeout();
		$settings = $this->index->getSettings();
		$maxShardsPerNode = $this->decideMaxShardsPerNodeForReindex();
		$settings->set( [
			'refresh_interval' => -1,
			'merge.policy.segments_per_tier' => 40,
			'merge.policy.max_merge_at_once' => 40,
			'routing.allocation.total_shards_per_node' => $maxShardsPerNode,
		] );

		if ( $processes > 1 ) {
			if ( !isset( $wgCirrusSearchWikimediaExtraPlugin[ 'id_hash_mod_filter' ] ) ||
					!$wgCirrusSearchWikimediaExtraPlugin[ 'id_hash_mod_filter' ] ) {
				$this->error( "Can't use multiple processes without \$wgCirrusSearchWikimediaExtraPlugin[ 'id_hash_mod_filter' ] = true", 1 );
			}

			$fork = new ForkController( $processes );
			$forkResult = $fork->start();
			// we don't want to share sockets between forks, so destroy the client.
			$this->destroyClients();

			switch ( $forkResult ) {
				case 'child':
					foreach ( $this->types as $i => $type ) {
						$oldType = $this->oldTypes[$i];
						$this->reindexInternal( $type, $oldType, $processes, $fork->getChildNumber(), $chunkSize, $retryAttempts );
					}
					die( 0 );
				case 'done':
					break;
				default:
					$this->error( "Unexpected result while forking:  $forkResult", 1 );
			}

			$this->outputIndented( "Verifying counts..." );
			// We can't verify counts are exactly equal because they won't be - we still push updates into
			// the old index while reindexing the new one.
			foreach ( $this->types as $i => $type ) {
				$oldType = $this->oldTypes[$i];
				$oldCount = (float) $oldType->count();
				$this->index->refresh();
				$newCount = (float) $type->count();
				$difference = $oldCount > 0 ? abs( $oldCount - $newCount ) / $oldCount : 0;
				if ( $difference > $acceptableCountDeviation ) {
					$this->output( "Not close enough!  old=$oldCount new=$newCount difference=$difference\n" );
					$this->error( 'Failed to load index - counts not close enough.  ' .
						"old=$oldCount new=$newCount difference=$difference.  " .
						'Check for warnings above.', 1 );
				}
			}
			$this->output( "done\n" );
		} else {
			foreach ( $this->types as $i => $type ) {
				$oldType = $this->oldTypes[$i];
				$this->reindexInternal( $type, $oldType, 1, 1, $chunkSize, $retryAttempts );
			}
		}

		// Revert settings changed just for reindexing
		$settings->set( [
			'refresh_interval' => $refreshInterval . 's',
			'merge.policy' => $this->mergeSettings,
		] );
	}

	public function optimize() {
		// Optimize the index so it'll be more compact for replication.  Not required
		// but should be helpful.
		$this->outputIndented( "\tOptimizing..." );
		try {
			// Reset the timeout just in case we lost it somewhere along the line
			$this->setConnectionTimeout();
			$this->index->optimize( [ 'max_num_segments' => 5 ] );
			$this->output( "Done\n" );
		} catch ( HttpException $e ) {
			if ( $e->getMessage() === 'Operation timed out' ) {
				$this->output( "Timed out...Continuing any way\n" );
				// To continue without blowing up we need to reset the connection.
				$this->destroyClients();
			} else {
				throw $e;
			}
		}
	}

	public function waitForShards() {
		if( !$this->replicaCount || $this->replicaCount === "false" ) {
			$this->outputIndented( "\tNo replicas, skipping.\n" );
			return;
		}
		$this->outputIndented( "\tWaiting for all shards to start...\n" );
		list( $lower, $upper ) = explode( '-', $this->replicaCount );
		$each = 0;
		while ( true ) {
			$health = $this->getHealth();
			$active = $health[ 'active_shards' ];
			$relocating = $health[ 'relocating_shards' ];
			$initializing = $health[ 'initializing_shards' ];
			$unassigned = $health[ 'unassigned_shards' ];
			$nodes = $health[ 'number_of_nodes' ];
			if ( $nodes < $lower ) {
				$this->error( "Require $lower replicas but only have $nodes nodes. "
					. "This is almost always due to misconfiguration, aborting.", 1 );
			}
			// If the upper range is all, expect the upper bound to be the number of nodes
			if ( $upper === 'all' ) {
				$upper = $nodes - 1;
			}
			$expectedReplicas =  min( max( $nodes - 1, $lower ), $upper );
			$expectedActive = $this->shardCount * ( 1 + $expectedReplicas );
			if ( $each === 0 || $active === $expectedActive ) {
				$this->outputIndented( "\t\tactive:$active/$expectedActive relocating:$relocating " .
					"initializing:$initializing unassigned:$unassigned\n" );
				if ( $active === $expectedActive ) {
					break;
				}
			}
			$each = ( $each + 1 ) % 20;
			sleep( 1 );
		}
	}

	/**
	 * @param Type $type
	 * @param Type $oldType
	 * @param int $children
	 * @param int $childNumber
	 * @param int|string $chunkSize
	 * @param int $retryAttempts
	 */
	private function reindexInternal( Type $type, Type $oldType, $children, $childNumber, $chunkSize, $retryAttempts ) {
		$filter = null;
		$messagePrefix = "";
		if ( $childNumber === 1 && $children === 1 ) {
			$this->outputIndented( "\t\tStarting single process reindex\n" );
		} else {
			if ( $childNumber >= $children ) {
				$this->error( "Invalid parameters - childNumber >= children ($childNumber >= $children) ", 1 );
			}
			$messagePrefix = "\t\t[$childNumber] ";
			$this->outputIndented( $messagePrefix . "Starting child process reindex\n" );
			// Note that it is not ok to abs(_uid.hashCode) because hashCode(Integer.MIN_VALUE) == Integer.MIN_VALUE
			$filter = new \CirrusSearch\Extra\Query\IdHashMod( $children, $childNumber );
		}
		$properties = $this->mappingConfig[$oldType->getName()]['properties'];
		try {
			$query = new Query();
			$query->setFields( [ '_id', '_source' ] );
			if ( $filter ) {
				$bool = new \Elastica\Query\BoolQuery();
				$bool->addFilter( $filter );
				$query->setQuery( $bool );
			}

			// Note here we dump from the current index (using the alias) so we can use Connection::getPageType
			$result = $oldType
				->search( $query, [
					'search_type' => 'scan',
					'scroll' => '1h',
					'size'=> $chunkSize,
				] );
			$totalDocsToReindex = $result->getResponse()->getData();
			$totalDocsToReindex = $totalDocsToReindex['hits']['total'];
			$this->outputIndented( $messagePrefix . "About to reindex $totalDocsToReindex documents\n" );
			$operationStartTime = microtime( true );
			$completed = 0;
			MWElasticUtils::iterateOverScroll( $this->oldIndex, $result->getResponse()->getScrollId(), '1h',
				function( $results ) use ( $properties, $retryAttempts, $messagePrefix, $type,
						&$completed, $totalDocsToReindex, $operationStartTime ) {
					$documents = [];
					foreach( $results as $result ) {
						$documents[] = $this->buildNewDocument( $result, $properties );
					}
					$this->withRetry( $retryAttempts, $messagePrefix, 'retrying as singles',
						function() use ( $type, $messagePrefix, $documents ) {
							$this->sendDocuments( $type, $messagePrefix, $documents );
						} );
					$completed += sizeof( $results );
					$rate = round( $completed / ( microtime( true ) - $operationStartTime ) );
					$this->outputIndented( $messagePrefix .
						"Reindexed $completed/$totalDocsToReindex documents at $rate/second\n");
				}, 0, $retryAttempts,
				function( $e, $errors ) use ( $messagePrefix ) {
					$this->sleepOnRetry( $e, $errors, $messagePrefix, 'fetching documents to reindex' );
				} );

			$this->outputIndented( $messagePrefix . "All done\n" );
		} catch ( ExceptionInterface $e ) {
			// Note that we can't fail the master here, we have to check how many documents are in the new index in the master.
			$type = get_class( $e );
			$error = ElasticsearchIntermediary::extractFullError( $e );
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Search backend error during reindex.  Error type is '{type}' ({error_type}) and message is:  {error_reason}",
				[
					'type' => $type,
					'error_type' => $error['type'],
					'error_reason' => $error['reason'],
				]
			);
			die( 1 );
		}
	}

	/**
	 * Build the new document to just contain keys which have a mapping in the new properties.  To clean
	 * out any old fields that we no longer use.
	 *
	 * @param \Elastica\Result $result original document retrieved from a search
	 * @param array $properties mapping properties
	 * @return Document
	 */
	private function buildNewDocument( \Elastica\Result $result, array $properties ) {
		// Build the new document to just contain keys which have a mapping in the new properties.  To clean
		// out any old fields that we no longer use.
		$data = Util::cleanUnusedFields( $result->getSource(), $properties );

		// This field was added July, 2016. For the first reindex that occurs after it was added it will
		// not exist in the documents, so add it here.
		if ( !isset( $data['wiki'] ) ) {
			$data['wiki'] = $this->searchConfig->getWikiId();
		}

		// Maybe instead the reindexer should know if we are converting from the old
		// style numeric page id's to the new style prefixed id's. This probably
		// works though.
		$docId = $this->searchConfig->maybeMakeId( $result->getId() );

		// Note that while setting the opType to create might improve performance slightly it can cause
		// trouble if the scroll returns the same id twice.  It can do that if the document is updated
		// during the scroll process.  I'm unclear on if it will always do that, so you still have to
		// perform the date based catch up after the reindex.
		return new Document( $docId, $data );
	}

	/**
	 * Get health information about the index
	 *
	 * @return array Response data array
	 */
	private function getHealth() {
		while ( true ) {
			$indexName = $this->index->getName();
			$path = "_cluster/health/$indexName";
			$response = $this->index->getClient()->request( $path );
			if ( $response->hasError() ) {
				$this->error( 'Error fetching index health but going to retry.  Message: ' . $response->getError() );
				sleep( 1 );
				continue;
			}
			return $response->getData();
		}
	}

	/**
	 * @return int
	 */
	private function decideMaxShardsPerNodeForReindex() {
		$health = $this->getHealth();
		$totalNodes = $health[ 'number_of_nodes' ];
		$totalShards = $this->shardCount * ( $this->getMaxReplicaCount() + 1 );
		return (int) ceil( 1.0 * $totalShards / $totalNodes );
	}

	/**
	 * @return int
	 */
	private function getMaxReplicaCount() {
		$replica = explode( '-', $this->replicaCount );
		return (int) $replica[ count( $replica ) - 1 ];
	}

	/**
	 * @param int $attempts
	 * @param string $messagePrefix
	 * @param string $description
	 * @param callable $func
	 * @return mixed
	 */
	private function withRetry( $attempts, $messagePrefix, $description, $func) {
		return MWElasticUtils::withRetry ( $attempts, $func,
			function( $e, $errors ) use ( $messagePrefix, $description ) {
				$this->sleepOnRetry( $e, $errors, $messagePrefix, $description );
			} );
	}

	/**
	 * @param ExceptionInterface $e exception caught
	 * @param int $errors number of errors
	 * @param string $messagePrefix
	 * @param string $description
	 */
	private function sleepOnRetry( ExceptionInterface $e, $errors, $messagePrefix, $description ) {
		$type = get_class( $e );
		$seconds = MWElasticUtils::backoffDelay( $errors );
		$message = ElasticsearchIntermediary::extractMessage( $e );
		$this->outputIndented( $messagePrefix . "Caught an error $description.  " .
			"Backing off for $seconds and retrying.  Error type is '$type' and message is:  $message\n" );
		sleep( $seconds );
	}

	/**
	 * Send documents to type with retry.
	 *
	 * @param Type $type
	 * @param string $messagePrefix
	 * @param Elastica\Document[]
	 */
	private function sendDocuments( Type $type, $messagePrefix, array $documents ) {
		try {
			$type->addDocuments( $documents );
		} catch ( ExceptionInterface $e ) {
			$errorType = get_class( $e );
			$message = ElasticsearchIntermediary::extractMessage( $e );
			$this->outputIndented( $messagePrefix . "Error adding documents in bulk.  Retrying as singles.  Error type is '$errorType' and message is:  $message" );
			foreach ( $documents as $document ) {
				// Continue using the bulk api because we're used to it.
				$type->addDocuments( [ $document ] );
			}
		}
	}

	/**
	 * Reset connection timeouts
	 */
	private function setConnectionTimeout() {
		$timeout = $this->searchConfig->get( 'CirrusSearchMaintenanceTimeout' );
		$this->connection->setTimeout( $timeout );
		$this->oldConnection->setTimeout( $timeout );
	}

	/**
	 * Destroy client connections
	 */
	private function destroyClients() {
		$this->connection->destroyClient();
		$this->oldConnection->destroyClient();
		// Destroying connections resets timeouts, so we have to reinstate them
		$this->setConnectionTimeout();
	}

	/**
	 * @param string $message
	 * @param mixed $channel
	 */
	protected function output( $message, $channel = null ) {
		if ( $this->out ) {
			$this->out->output( $message, $channel );
		}
	}

	/**
	 * @param string $message
	 */
	private function outputIndented( $message ) {
		if ( $this->out ) {
			$this->out->outputIndented( $message );
		}
	}

	/**
	 * @param string $message
	 * @param int $die
	 */
	private function error( $message, $die = 0 ) {
		// @todo: I'll want to get rid of this method, but this patch will be big enough already
		// @todo: I'll probably want to throw exceptions and/or return Status objects instead, later

		if ( $this->out ) {
			$this->out->error( $message, $die );
		}

		$die = intval( $die );
		if ( $die > 0 ) {
			die( $die );
		}
	}
}
