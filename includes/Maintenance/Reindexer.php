<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\ElasticsearchIntermediary;
use Elastica\Document;
use Elastica\Exception\Connection\HttpException;
use Elastica\Exception\ExceptionInterface;
use Elastica\Filter\Script;
use Elastica\Index;
use Elastica\Query;
use Elastica\Type;

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
	 * This one's public because it's used in a Closure, where $this is passed
	 * in as $self (because PHP<5.4 doesn't properly support $this in closures)
	 *
	 * @var Index
	 */
	public $index;

	/**
	 * @var \Elastica\Client
	 */
	private $client;

	/**
	 * @var string
	 */
	private $specificIndexName;

	/**
	 * @var Type
	 */
	private $type;

	/**
	 * @var Type
	 */
	private $oldType;

	/**
	 * @var int
	 */
	private $shardCount;

	/**
	 * @var string
	 */
	private $replicaCount;

	/**
	 * @var int
	 */
	private $connectionTimeout;

	/**
	 * @var array
	 */
	private $mergeSettings;

	/**
	 * @var array
	 */
	private $mappingConfig;

	/**
	 * @var \ElasticaConnection
	 */
	private $connection;

	/**
	 * @var Maintenance
	 */
	private $out;

	/**
	 * @param Index $index
	 * @param \ElasticaConnection $connection
	 * @param Type $type
	 * @param Type $oldType
	 * @param int $shardCount
	 * @param string $replicaCount
	 * @param int $connectionTimeout
	 * @param array $mergeSettings
	 * @param array $mappingConfig
	 * @param Maintenance $out
	 */
	public function __construct( Index $index, \ElasticaConnection $connection, Type $type, Type $oldType, $shardCount, $replicaCount, $connectionTimeout, array $mergeSettings, array $mappingConfig, Maintenance $out = null ) {
		// @todo: this constructor has too many arguments - refactor!
		$this->index = $index;
		$this->client = $this->index->getClient();
		$this->specificIndexName = $this->index->getName();
		$this->connection = $connection;
		$this->type = $type;
		$this->oldType = $oldType;
		$this->shardCount = $shardCount;
		$this->replicaCount = $replicaCount;
		$this->connectionTimeout = $connectionTimeout;
		$this->mergeSettings = $mergeSettings;
		$this->mappingConfig = $mappingConfig;
		$this->out = $out;
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
		// Set some settings that should help io load during bulk indexing.  We'll have to
		// optimize after this to consolidate down to a proper number of shards but that is
		// is worth the price.  total_shards_per_node will help to make sure that each shard
		// has as few neighbors as possible.
		$settings = $this->index->getSettings();
		$maxShardsPerNode = $this->decideMaxShardsPerNodeForReindex();
		$settings->set( array(
			'refresh_interval' => -1,
			'merge.policy.segments_per_tier' => 40,
			'merge.policy.max_merge_at_once' => 40,
			'routing.allocation.total_shards_per_node' => $maxShardsPerNode,
		) );

		if ( $processes > 1 ) {
			$fork = new ReindexForkController( $processes );
			$forkResult = $fork->start();
			// Forking clears the timeout so we have to reinstate it.
			$this->setConnectionTimeout();

			switch ( $forkResult ) {
				case 'child':
					$this->reindexInternal( $processes, $fork->getChildNumber(), $chunkSize, $retryAttempts );
					die( 0 );
				case 'done':
					break;
				default:
					$this->error( "Unexpected result while forking:  $forkResult", 1 );
			}

			$this->outputIndented( "Verifying counts..." );
			// We can't verify counts are exactly equal because they won't be - we still push updates into
			// the old index while reindexing the new one.
			$oldCount = (float) $this->oldType->count();
			$this->index->refresh();
			$newCount = (float) $this->type->count();
			$difference = $oldCount > 0 ? abs( $oldCount - $newCount ) / $oldCount : 0;
			if ( $difference > $acceptableCountDeviation ) {
				$this->output( "Not close enough!  old=$oldCount new=$newCount difference=$difference\n" );
				$this->error( 'Failed to load index - counts not close enough.  ' .
					"old=$oldCount new=$newCount difference=$difference.  " .
					'Check for warnings above.', 1 );
			}
			$this->output( "done\n" );
		} else {
			$this->reindexInternal( 1, 1, $chunkSize, $retryAttempts );
		}

		// Revert settings changed just for reindexing
		$settings->set( array(
			'refresh_interval' => $refreshInterval . 's',
			'merge.policy' => $this->mergeSettings,
		) );
	}

	public function optimize() {
		// Optimize the index so it'll be more compact for replication.  Not required
		// but should be helpful.
		$this->outputIndented( "\tOptimizing..." );
		try {
			// Reset the timeout just in case we lost it somewhere along the line
			$this->setConnectionTimeout();
			$this->index->optimize( array( 'max_num_segments' => 5 ) );
			$this->output( "Done\n" );
		} catch ( HttpException $e ) {
			if ( $e->getMessage() === 'Operation timed out' ) {
				$this->output( "Timed out...Continuing any way\n" );
				// To continue without blowing up we need to reset the connection.
				$this->destroySingleton();
				$this->setConnectionTimeout();
			} else {
				throw $e;
			}
		}
	}

	public function waitForShards() {
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

	private function reindexInternal( $children, $childNumber, $chunkSize, $retryAttempts ) {
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
			$filter = new Script( array(
				'script' => "(doc['_uid'].value.hashCode() & Integer.MAX_VALUE) % $children == $childNumber",
				'lang' => 'groovy'
			) );
		}
		$pageProperties = $this->mappingConfig['page']['properties'];
		try {
			$query = new Query();
			$query->setFields( array( '_id', '_source' ) );
			if ( $filter ) {
				$query->setFilter( $filter );
			}

			// Note here we dump from the current index (using the alias) so we can use Connection::getPageType
			$result = $this->oldType
				->search( $query, array(
					'search_type' => 'scan',
					'scroll' => '1h',
					'size'=> $chunkSize,
				) );
			$totalDocsToReindex = $result->getResponse()->getData();
			$totalDocsToReindex = $totalDocsToReindex['hits']['total'];
			$this->outputIndented( $messagePrefix . "About to reindex $totalDocsToReindex documents\n" );
			$operationStartTime = microtime( true );
			$completed = 0;
			$self = $this;
			while ( true ) {
				wfProfileIn( __METHOD__ . '::receiveDocs' );
				$result = $this->withRetry( $retryAttempts, $messagePrefix, 'fetching documents to reindex',
					function() use ( $self, $result ) {
						return $self->index->search( array(), array(
							'scroll_id' => $result->getResponse()->getScrollId(),
							'scroll' => '1h'
						) );
					} );
				wfProfileOut( __METHOD__ . '::receiveDocs' );
				if ( !$result->count() ) {
					$this->outputIndented( $messagePrefix . "All done\n" );
					break;
				}
				wfProfileIn( __METHOD__ . '::packageDocs' );
				$documents = array();
				while ( $result->current() ) {
					// Build the new document to just contain keys which have a mapping in the new properties.  To clean
					// out any old fields that we no longer use.  Note that this filter is only a single level which is
					// likely ok for us.
					$document = new Document( $result->current()->getId(),
						array_intersect_key( $result->current()->getSource(), $pageProperties ) );
					// Note that while setting the opType to create might improve performance slightly it can cause
					// trouble if the scroll returns the same id twice.  It can do that if the document is updated
					// during the scroll process.  I'm unclear on if it will always do that, so you still have to
					// perform the date based catch up after the reindex.
					$documents[] = $document;
					$result->next();
				}
				wfProfileOut( __METHOD__ . '::packageDocs' );
				$this->withRetry( $retryAttempts, $messagePrefix, 'retrying as singles',
					function() use ( $self, $messagePrefix, $documents ) {
						$self->sendDocuments( $messagePrefix, $documents );
					} );
				$completed += $result->count();
				$rate = round( $completed / ( microtime( true ) - $operationStartTime ) );
				$this->outputIndented( $messagePrefix .
					"Reindexed $completed/$totalDocsToReindex documents at $rate/second\n");
			}
		} catch ( ExceptionInterface $e ) {
			// Note that we can't fail the master here, we have to check how many documents are in the new index in the master.
			$type = get_class( $e );
			$message = ElasticsearchIntermediary::extractMessage( $e );
			wfLogWarning( "Search backend error during reindex.  Error type is '$type' and message is:  $message" );
			die( 1 );
		}
	}

	private function getHealth() {
		while ( true ) {
			$indexName = $this->specificIndexName;
			$path = "_cluster/health/$indexName";
			$response = $this->client->request( $path );
			if ( $response->hasError() ) {
				$this->error( 'Error fetching index health but going to retry.  Message: ' . $response->getError() );
				sleep( 1 );
				continue;
			}
			return $response->getData();
		}
	}

	private function decideMaxShardsPerNodeForReindex() {
		$health = $this->getHealth();
		$totalNodes = $health[ 'number_of_nodes' ];
		$totalShards = $this->shardCount * ( $this->getMaxReplicaCount() + 1 );
		return ceil( 1.0 * $totalShards / $totalNodes );
	}

	private function getMaxReplicaCount() {
		$replica = explode( '-', $this->replicaCount );
		return $replica[ count( $replica ) - 1 ];
	}

	/**
	 * @param int $attempts
	 * @param string $messagePrefix
	 * @param string $description
	 * @param callable $func
	 * @return mixed
	 */
	private function withRetry( $attempts, $messagePrefix, $description, $func ) {
		$errors = 0;
		while ( true ) {
			if ( $errors < $attempts ) {
				try {
					return $func();
				} catch ( ExceptionInterface $e ) {
					$errors += 1;
					// Random backoff with lowest possible upper bound as 16 seconds.
					// With the default maximum number of errors (5) this maxes out at 256 seconds.
					$seconds = rand( 1, pow( 2, 3 + $errors ) );
					$type = get_class( $e );
					$message = ElasticsearchIntermediary::extractMessage( $e );
					$this->outputIndented( $messagePrefix . "Caught an error $description.  " .
						"Backing off for $seconds and retrying.  Error type is '$type' and message is:  $message\n" );
					sleep( $seconds );
				}
			} else {
				return $func();
			}
		}
	}

	private function sendDocuments( $messagePrefix, $documents ) {
		try {
			$this->type->addDocuments( $documents );
		} catch ( ExceptionInterface $e ) {
			$type = get_class( $e );
			$message = ElasticsearchIntermediary::extractMessage( $e );
			$this->outputIndented( $messagePrefix . "Error adding documents in bulk.  Retrying as singles.  Error type is '$type' and message is:  $message" );
			foreach ( $documents as $document ) {
				// Continue using the bulk api because we're used to it.
				$this->type->addDocuments( array( $document ) );
			}
		}
	}

	private function setConnectionTimeout() {
		$this->connection->setTimeout2( $this->connectionTimeout );
	}

	private function destroySingleton() {
		$this->connection->destroyClient();
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
	protected function outputIndented( $message ) {
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
