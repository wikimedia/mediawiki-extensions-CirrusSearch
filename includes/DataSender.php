<?php

namespace CirrusSearch;

use Elastica\Exception\Bulk\ResponseException;
use MediaWiki\Logger\LoggerFactory;
use Status;
use Title;
use WikiPage;

/**
 * Handles non-maintenance write operations to the elastic search cluster.
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
class DataSender extends ElasticsearchIntermediary {
	const ALL_INDEXES_FROZEN_NAME = 'freeze_everything';

	/**
	 * @var Connection
	 */
	public function __construct( Connection $conn ) {
		parent::__construct( $conn, null, null );
		$this->log = LoggerFactory::getInstance( 'CirrusSearch' );
		$this->failedLog = LoggerFactory::getInstance( 'CirrusSearchChangeFailed' );
	}

	/**
	 * Disallow writes to the specified indexes.
	 *
	 * @param string[]|null $indexes List of index types to disallow writes to.
	 *  null means to prevent indexing in all indexes across all wikis.
	 */
	public function freezeIndexes( array $indexes = null ) {
		global $wgCirrusSearchUpdateConflictRetryCount;

		if ( $indexes === null ) {
			$names = array( self::ALL_INDEXES_FROZEN_NAME );
		} elseif ( count( $indexes ) === 0 ) {
			return;
		} else {
			$names = $this->indexesToIndexNames( $indexes );
		}

		$this->log->info( "Freezing writes to: " . implode( ',', $names ) );

		$documents = array();
		foreach ( $names as $indexName ) {
			$doc = new \Elastica\Document( $indexName, array(
				'name' => $indexName,
			) );
			$doc->setDocAsUpsert( true );
			$doc->setRetryOnConflict( $wgCirrusSearchUpdateConflictRetryCount );
			$documents[] = $doc;
		}

		$client = $this->connection->getClient();
		$type = $this->connection->getFrozenIndexNameType();
		// Elasticsearch has a queue capacity of 50 so if $data
		// contains 50 documents it could bump up against the max.  So
		// we chunk it and do them sequentially.
		foreach ( array_chunk( $documents, 30 ) as $data ) {
			$bulk = new \Elastica\Bulk( $client );
			$bulk->setType( $type );
			$bulk->addData( $data, 'update' );
			$bulk->send();
		}

		// Ensure our freeze is immediatly seen (mostly for testing
		// purposes)
		$type->getIndex()->refresh();
	}

	/**
	 * Allow writes to the specified indexes.
	 *
	 * @param string[]|null $indexes List of index types to allow writes to.
	 *  null means to remove the global freeze on all indexes. Null does not
	 *  thaw indexes that were individually frozen.
	 */
	public function thawIndexes( array $indexes = null ) {
		if ( $indexes === null ) {
			$names = array( self::ALL_INDEXES_FROZEN_NAME );
		} elseif ( count( $indexes ) === 0 ) {
			return;
		} else {
			$names = $this->indexesToIndexNames( $indexes );
		}

		$this->log->info( "Thawing writes to " . implode( ',', $names ) );

		$this->connection->getFrozenIndexNameType()->deleteIds( $names );
	}

	/**
	 * Checks if all the specified indexes are available for writes. They might
	 * not currently allow writes during procedures like reindexing or rolling
	 * restarts.
	 *
	 * @param string[] $indexes List of index names to check for availability.
	 * @param bool $areIndexesFullyQualified Set to true if the provided $indexes are
	 *  already fully qualified elasticsearch index names.
	 * @return bool
	 */
	public function areIndexesAvailableForWrites( array $indexes, $areIndexesFullyQualified = false ) {
		if ( count( $indexes ) === 0 ) {
			return true;
		}
		if ( !$areIndexesFullyQualified ) {
			$indexes = $this->indexesToIndexNames( $indexes );
		}

		$ids = new \Elastica\Query\Ids( null, $indexes );
		$ids->addId( self::ALL_INDEXES_FROZEN_NAME );
		$resp = $this->connection->getFrozenIndexNameType()->search( $ids );

		if ( $resp->count() === 0 ) {
			$this->log->debug( "Allowed writes to " . implode( ',', $indexes ) );
			return true;
		} else {
			$this->log->debug( "Denied writes to " . implode( ',', $indexes ) );
			return false;
		}
	}

	/**
	 * @param string $indexType type of index to which to send $data
	 * @param (\Elastica\Script|\Elastica\Document)[] $data documents to send
	 * @param null|string $shardTimeout How long should elaticsearch wait for an offline
	 *   shard.  Defaults to null, meaning don't wait.  Null is more efficient when sending
	 *   multiple pages because Cirrus will use Elasticsearch's bulk API.  Timeout is in
	 *   Elasticsearch's time format.
	 * @return Status
	 */
	public function sendData( $indexType, $data, $shardTimeout ) {
		$documentCount = count( $data );
		if ( $documentCount === 0 ) {
			return Status::newGood();
		}

		if ( !$this->areIndexesAvailableForWrites( array( $indexType ) ) ) {
			return Status::newFatal( 'cirrussearch-indexes-frozen' );
		}

		$exception = null;
		try {
			$pageType = $this->connection->getPageType( wfWikiId(), $indexType );
			$this->start( "sending {numBulk} documents to the {indexType} index", array(
				'numBulk' => $documentCount,
				'indexType' => $indexType,
				'queryType' => 'send_data_write',
			) );
			$bulk = new \Elastica\Bulk( $this->connection->getClient() );
			if ( $shardTimeout ) {
				$bulk->setShardTimeout( $shardTimeout );
			}
			$bulk->setType( $pageType );
			$bulk->addData( $data, 'update' );
			$bulk->send();
		} catch ( ResponseException $e ) {
			$cirrusLog = $this->log;
			$missing = $this->bulkResponseExceptionIsJustDocumentMissing( $e,
				function( $id ) use ( $cirrusLog, $e ) {
					$cirrusLog->info(
						"Updating a page that doesn't yet exist in Elasticsearch: {id}",
						array( 'id' => $id )
					);
				}
			);
			if ( !$missing ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}

		if ( $exception === null ) {
			$this->success();
			return Status::newGood();
		} else {
			$this->failure( $exception );
			$documentIds = array_map( function( $d ) {
				return $d->getId();
			}, $data );
			$this->failedLog->warning(
				'Update for doc ids: ' . implode( ',', $documentIds ),
				array( 'exception' => $exception )
			);
			return Status::newFatal( 'cirrussearch-failed-send-data' );
		}
	}

	/**
	 * Send delete requests to Elasticsearch.
	 *
	 * @param int[] $ids ids to delete from Elasticsearch
	 * @param string|null $indexType index from which to delete.  null means all.
	 * @return Status
	 */
	public function sendDeletes( $ids, $indexType = null ) {
		if ( $indexType === null ) {
			$indexes = $this->connection->getAllIndexTypes();
		} else {
			$indexes = array( $indexType );
		}

		if ( !$this->areIndexesAvailableForWrites( $indexes ) ) {
			return Status::newFatal( 'cirrussearch-indexes-frozen' );
		}

		$idCount = count( $ids );
		if ( $idCount !== 0 ) {
			try {
				foreach ( $indexes as $indexType ) {
					$this->start( "deleting {numIds} from {indexType}", array(
						'numIds' => $idCount,
						'indexType' => $indexType,
						'queryType' => 'send_deletes',
					) );
					$this->connection->getPageType( wfWikiId(), $indexType )->deleteIds( $ids );
					$this->success();
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$this->failure( $e );
				$this->failedLog->warning(
					'Delete for ids: ' . implode( ',', $ids ),
					array( 'exception' => $e )
				);
				return Status::newFatal( 'cirrussearch-failed-send-deletes' );
			}
		}

		return Status::newGood();
	}

	/**
	 * @param string $localSite The wikiId to add/remove from local_sites_with_dupe
	 * @param string $indexName The name of the index to perform updates to
	 * @param array $otherActions A list of arrays each containing the id within elasticsearch ('id') and the article id within $localSite ('articleId')
	 * @return Status
	 */
	public function sendOtherIndexUpdates( $localSite, $indexName, array $otherActions ) {
		if ( !$this->areIndexesAvailableForWrites( array( $indexName ), true ) ) {
			return Status::newFatal( 'cirrussearch-indexes-frozen' );
		}

		$client = $this->connection->getClient();
		$status = Status::newGood();
		foreach ( array_chunk( $otherActions, 30 ) as $updates ) {
			$bulk = new \Elastica\Bulk( $client );
			$articleIDs = array();
			foreach ( $updates as $update ) {
				$title = Title::makeTitle( $update['ns'], $update['dbKey'] );
				$action = $this->decideRequiredSetAction( $title );
				$this->log->debug( "Performing `$action` for {$update['dbKey']} in ns {$update['ns']} on $localSite against id ${update['id']} in $indexName" );
				$script = new \Elastica\Script(
					'super_detect_noop',
					array(
						'source' => array(
							'local_sites_with_dupe' => array( $action => $localSite ),
						),
						'handlers' => array( 'local_sites_with_dupe' => 'set' ),
					),
					'native'
				);
				$script->setId( $update['id'] );
				$script->setParam( '_type', 'page' );
				$script->setParam( '_index', $indexName );
				$bulk->addScript( $script, 'update' );
				$titles[] = $title;
			}

			// Execute the bulk update
			$exception = null;
			try {
				$this->start( "updating {numBulk} documents in other indexes", array(
					'numBulk' => count( $updates ),
					'queryType' => 'send_data_other_idx_write',
				) );
				$bulk->send();
			} catch ( \Elastica\Exception\Bulk\ResponseException $e ) {
				if ( !$this->bulkResponseExceptionIsJustDocumentMissing( $e ) ) {
					$exception = $e;
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$exception = $e;
			}
			if ( $exception === null ) {
				$this->success();
			} else {
				$this->failure( $e );
				$this->failedLog->warning(
					"OtherIndex update for articles: " . implode( ',', $titles ),
					array( 'exception' => $e )
				);
				$status->error( 'cirrussearch-failed-update-otherindex' );
			}
		}

		return $status;
	}

	/**
	 * Decide what action is required to the other index to make it up
	 * to data with the current wiki state. This will always check against
	 * the master database.
	 *
	 * @param Title $title The title to decide the action for
	 * @return string The set action to be performed. Either 'add' or 'remove'
	 */
	protected function decideRequiredSetAction( Title $title ) {
		$page = new WikiPage( $title );
		$page->loadPageData( 'fromdbmaster' );
		if ( $page->exists() ) {
			return 'add';
		} else {
			return 'remove';
		}
	}

	/**
	 * Check if $exception is a bulk response exception that just contains
	 * document is missing failures.
	 *
	 * @param ResponseException $exception exception to check
	 * @param callable|null $logCallback Callback in which to do some logging.
	 *   Callback will be passed the id of the missing document.
	 * @return bool
	 */
	protected function bulkResponseExceptionIsJustDocumentMissing( ResponseException $exception, $logCallback = null ) {
		$justDocumentMissing = true;
		foreach ( $exception->getResponseSet()->getBulkResponses() as $bulkResponse ) {
			if ( !$bulkResponse->hasError() ) {
				continue;
			}

			$pos = strpos( $bulkResponse->getError(), 'DocumentMissingException' );
			if ( $pos === false ) {
				$justDocumentMissing = false;
			} elseif ( $logCallback ) {
				// This is generally not an error but we should
				// log it to see how many we get
				$id = $bulkResponse->getAction()->getData()->getId();
				call_user_func( $logCallback, $id );
			}
		}
		return $justDocumentMissing;
	}

	/**
	 * @param string[]
	 * @return string[]
	 */
	public function indexesToIndexNames( array $indexes ) {
		$names = array();
		$wikiId = wfWikiId();
		foreach ( $indexes as $indexType ) {
			$names[] = $this->connection->getIndexName( $wikiId, $indexType );
		}
		return $names;
	}
}
