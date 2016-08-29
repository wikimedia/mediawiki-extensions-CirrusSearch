<?php

namespace CirrusSearch;

use CirrusSearch\SearchConfig;
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

	/** @var \Psr\Log\LoggerInterface */
	private $log;

	/** @var \Psr\Log\LoggerInterface */
	private $failedLog;

	/**
	 * @var string
	 */
	private $indexBaseName;

	/**
	 * @var SearchConfig
	 */
	private $searchConfig;

	/**
	 * @var Connection
	 */
	public function __construct( Connection $conn, SearchConfig $config ) {
		parent::__construct( $conn, null, 0 );
		$this->log = LoggerFactory::getInstance( 'CirrusSearch' );
		$this->failedLog = LoggerFactory::getInstance( 'CirrusSearchChangeFailed' );
		$this->indexBaseName = $config->get( SearchConfig::INDEX_BASE_NAME );
		$this->searchConfig = $config;
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
			$names = [ self::ALL_INDEXES_FROZEN_NAME ];
		} else {
			if ( count( $indexes ) === 0 ) {
				return;
			}
			$names = $this->indexesToIndexNames( $indexes );
		}

		$this->log->info( "Freezing writes to: " . implode( ',', $names ) );

		$documents = [];
		foreach ( $names as $indexName ) {
			$doc = new \Elastica\Document( $indexName, [
				'name' => $indexName,
			] );
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

		// Ensure our freeze is immediately seen (mostly for testing
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
			$names = [ self::ALL_INDEXES_FROZEN_NAME ];
		} else {
			if ( count( $indexes ) === 0 ) {
				return;
			}
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
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @param string $indexType type of index to which to send $data
	 * @param (\Elastica\Script|\Elastica\Document)[] $data documents to send
	 * @return Status
	 */
	public function sendData( $indexType, $data ) {
		$documentCount = count( $data );
		if ( $documentCount === 0 ) {
			return Status::newGood();
		}

		if ( !$this->areIndexesAvailableForWrites( [ $indexType ] ) ) {
			return Status::newFatal( 'cirrussearch-indexes-frozen' );
		}

		$exception = null;
		$responseSet = null;
		$justDocumentMissing = false;
		try {
			$pageType = $this->connection->getPageType( $this->indexBaseName, $indexType );
			$this->start( "sending {numBulk} documents to the {indexType} index", [
				'numBulk' => $documentCount,
				'indexType' => $indexType,
				'queryType' => 'send_data_write',
			] );
			$bulk = new \Elastica\Bulk( $this->connection->getClient() );
			$bulk->setShardTimeout( $this->searchConfig->get( 'CirrusSearchUpdateShardTimeout' ) );
			$bulk->setType( $pageType );
			$bulk->addData( $data, 'update' );
			$responseSet = $bulk->send();
		} catch ( ResponseException $e ) {
			$justDocumentMissing = $this->bulkResponseExceptionIsJustDocumentMissing( $e,
				function( $docId ) use ( $e, $indexType ) {
					$this->log->info(
						"Updating a page that doesn't yet exist in Elasticsearch: {docId}",
						[ 'docId' => $docId, 'indexType' => $indexType ]
					);
				}
			);
			if ( !$justDocumentMissing ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}

		$validResponse = $responseSet !== null && count( $responseSet->getBulkResponses() ) > 0;
		if ( $exception === null && ( $justDocumentMissing || $validResponse ) ) {
			$this->success();
			return Status::newGood();
		} else {
			$this->failure( $exception );
			$documentIds = array_map( function( $d ) {
				return $d->getId();
			}, $data );
			$this->failedLog->warning(
				'Update for doc ids: ' . implode( ',', $documentIds ),
				$exception ? [ 'exception' => $exception ] : []
			);
			return Status::newFatal( 'cirrussearch-failed-send-data' );
		}
	}

	/**
	 * Send delete requests to Elasticsearch.
	 *
	 * @param string[] $docIds elasticsearch document ids to delete
	 * @param string|null $indexType index from which to delete.  null means all.
	 * @return Status
	 */
	public function sendDeletes( $docIds, $indexType = null ) {
		if ( $indexType === null ) {
			$indexes = $this->connection->getAllIndexTypes();
		} else {
			$indexes = [ $indexType ];
		}

		if ( !$this->areIndexesAvailableForWrites( $indexes ) ) {
			return Status::newFatal( 'cirrussearch-indexes-frozen' );
		}

		$idCount = count( $docIds );
		if ( $idCount !== 0 ) {
			try {
				foreach ( $indexes as $indexType ) {
					$this->start( "deleting {numIds} from {indexType}", [
						'numIds' => $idCount,
						'indexType' => $indexType,
						'queryType' => 'send_deletes',
					] );
					$this->connection->getPageType( $this->indexBaseName, $indexType )->deleteIds( $docIds );
					$this->success();
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$this->failure( $e );
				$this->failedLog->warning(
					'Delete for ids: ' . implode( ',', $docIds ),
					[ 'exception' => $e ]
				);
				return Status::newFatal( 'cirrussearch-failed-send-deletes' );
			}
		}

		return Status::newGood();
	}

	/**
	 * @param string $localSite The wikiId to add/remove from local_sites_with_dupe
	 * @param string $indexName The name of the index to perform updates to
	 * @param array $otherActions A list of arrays each containing the id within elasticsearch ('docId') and the article namespace ('ns') and DB key ('dbKey') at the within $localSite
	 * @return Status
	 */
	public function sendOtherIndexUpdates( $localSite, $indexName, array $otherActions ) {
		if ( !$this->areIndexesAvailableForWrites( [ $indexName ], true ) ) {
			return Status::newFatal( 'cirrussearch-indexes-frozen' );
		}

		$client = $this->connection->getClient();
		$status = Status::newGood();
		foreach ( array_chunk( $otherActions, 30 ) as $updates ) {
			$bulk = new \Elastica\Bulk( $client );
			$titles = [];
			foreach ( $updates as $update ) {
				$title = Title::makeTitle( $update['ns'], $update['dbKey'] );
				$action = $this->decideRequiredSetAction( $title );
				$script = new \Elastica\Script\Script(
					'super_detect_noop',
					[
						'source' => [
							'local_sites_with_dupe' => [ $action => $localSite ],
						],
						'handlers' => [ 'local_sites_with_dupe' => 'set' ],
					],
					'native'
				);
				$script->setId( $update['docId'] );
				$script->setParam( '_type', 'page' );
				$script->setParam( '_index', $indexName );
				$bulk->addScript( $script, 'update' );
				$titles[] = $title;
			}

			// Execute the bulk update
			$exception = null;
			try {
				$this->start( "updating {numBulk} documents in other indexes", [
					'numBulk' => count( $updates ),
					'queryType' => 'send_data_other_idx_write',
				] );
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
				$this->failure( $exception );
				$this->failedLog->warning(
					"OtherIndex update for articles: " . implode( ',', $titles ),
					[ 'exception' => $exception ]
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

			$error = $bulkResponse->getFullError();
			if ( is_string( $error ) ) {
				// es 1.7 cluster
				$message = $bulkResponse->getError();
				if ( false === strpos( $message, 'DocumentMissingException' ) ) {
					$justDocumentMissing = false;
					continue;
				}
			} else {
				// es 2.x cluster
				if ( $error['type'] !== 'document_missing_exception' ) {
					$justDocumentMissing = false;
					continue;
				}
			}

			if ( $logCallback ) {
				// This is generally not an error but we should
				// log it to see how many we get
				$action = $bulkResponse->getAction();
				$docId = 'missing';
				if ( $action instanceof \Elastica\Bulk\Action\AbstractDocument ) {
					$docId = $action->getData()->getId();
				}
				call_user_func( $logCallback, $docId );
			}
		}
		return $justDocumentMissing;
	}

	/**
	 * @param string[] $indexes
	 * @return string[]
	 */
	public function indexesToIndexNames( array $indexes ) {
		$names = [];
		foreach ( $indexes as $indexType ) {
			$names[] = $this->connection->getIndexName( $this->indexBaseName, $indexType );
		}
		return $names;
	}
}
