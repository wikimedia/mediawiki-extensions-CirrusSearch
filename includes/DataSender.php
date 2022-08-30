<?php

namespace CirrusSearch;

use CirrusSearch\BuildDocument\BuildDocument;
use CirrusSearch\BuildDocument\BuildDocumentException;
use CirrusSearch\Search\CirrusIndexField;
use Elastica\Bulk\Action\AbstractDocument;
use Elastica\Document;
use Elastica\Exception\Bulk\ResponseException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Status;
use Title;
use Wikimedia\Assert\Assert;
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
	 * @param Connection $conn
	 * @param SearchConfig $config
	 */
	public function __construct( Connection $conn, SearchConfig $config ) {
		parent::__construct( $conn, null, 0 );
		$this->log = LoggerFactory::getInstance( 'CirrusSearch' );
		$this->failedLog = LoggerFactory::getInstance( 'CirrusSearchChangeFailed' );
		$this->indexBaseName = $config->get( SearchConfig::INDEX_BASE_NAME );
		$this->searchConfig = $config;
	}

	/**
	 * @param string $indexSuffix
	 * @param string[] $docIds
	 * @param string $tagField
	 * @param string $tagPrefix
	 * @param string|string[]|null $tagNames A tag name or list of tag names. Each tag will be
	 *   set for each document ID. Omit for tags which are fully defined by their prefix.
	 * @param int[]|int[][]|null $tagWeights An optional map of docid => weight. When $tagName is
	 *   null, the weight is an integer. When $tagName is not null, the weight is itself a
	 *   tagname => int map. Weights are between 1-1000, and can be omitted (in which case no
	 *   update will be sent for the corresponding docid/tag combination).
	 * @param int $batchSize
	 * @return Status
	 */
	public function sendUpdateWeightedTags(
		string $indexSuffix,
		array $docIds,
		string $tagField,
		string $tagPrefix,
		$tagNames = null,
		array $tagWeights = null,
		int $batchSize = 30
	): Status {
		Assert::parameterType( [ 'string', 'array', 'null' ], $tagNames, '$tagNames' );
		if ( is_array( $tagNames ) ) {
			Assert::parameterElementType( 'string', $tagNames, '$tagNames' );
		}
		if ( $tagNames === null ) {
			$tagNames = [ 'exists' ];
			if ( $tagWeights !== null ) {
				$tagWeights = [ 'exists' => $tagWeights ];
			}
		} elseif ( is_string( $tagNames ) ) {
			$tagNames = [ $tagNames ];
		}

		Assert::precondition( strpos( $tagPrefix, '/' ) === false,
			"invalid tag prefix $tagPrefix: must not contain /" );
		foreach ( $tagNames as $tagName ) {
			Assert::precondition( strpos( $tagName, '|' ) === false,
				"invalid tag name $tagName: must not contain |" );
		}
		if ( $tagWeights ) {
			foreach ( $tagWeights as $docId => $docWeights ) {
				Assert::precondition( in_array( $docId, $docIds ),
					"document ID $docId used in \$tagWeights but not found in \$docIds" );
				foreach ( $docWeights as $tagName => $weight ) {
					Assert::precondition( in_array( $tagName, $tagNames, true ),
						"tag name $tagName used in \$tagWeights but not found in \$tagNames" );
					Assert::precondition( is_int( $weight ), "weights must be integers but $weight is "
						. gettype( $weight ) );
					Assert::precondition( $weight >= 1 && $weight <= 1000,
						"weights must be between 1 and 1000 (found: $weight)" );
				}
			}
		}

		$client = $this->connection->getClient();
		$status = Status::newGood();
		$pageIndex = $this->connection->getIndex( $this->indexBaseName, $indexSuffix );
		foreach ( array_chunk( $docIds, $batchSize ) as $docIdsChunk ) {
			$bulk = new \Elastica\Bulk( $client );
			$bulk->setIndex( $pageIndex );
			foreach ( $docIdsChunk as $docId ) {
				$tags = [];
				foreach ( $tagNames as $tagName ) {
					$tagValue = "$tagPrefix/$tagName";
					if ( $tagWeights !== null ) {
						if ( !isset( $tagWeights[$docId][$tagName] ) ) {
							continue;
						}
						// To pass the assertions above, the weight must be either an int, a float
						// with an integer value, or a string representation of one of those.
						// Convert to int to ensure there is no trailing '.0'.
						$tagValue .= '|' . (int)$tagWeights[$docId][$tagName];
					}
					$tags[] = $tagValue;
				}
				if ( !$tags ) {
					continue;
				}
				$script = new \Elastica\Script\Script( 'super_detect_noop', [
					'source' => [ $tagField => $tags ],
					'handlers' => [ $tagField => CirrusIndexField::MULTILIST_HANDLER ],
				], 'super_detect_noop' );
				$script->setId( $docId );
				$bulk->addScript( $script, 'update' );
			}

			if ( !$bulk->getActions() ) {
				continue;
			}

			// Execute the bulk update
			$exception = null;
			try {
				$this->start( new BulkUpdateRequestLog( $this->connection->getClient(),
					'updating {numBulk} documents',
					'send_data_reset_weighted_tags',
					[ 'numBulk' => count( $docIdsChunk ), 'index' => $pageIndex->getName() ]
				) );
				$bulk->send();
			} catch ( ResponseException $e ) {
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
				$this->failedLog->warning( "Update weighted tag {weightedTagFieldName} for {weightedTagPrefix} in articles: {documents}",
					[
						'exception' => $exception,
						'weightedTagFieldName' => $tagField,
						'weightedTagPrefix' => $tagPrefix,
						'weightedTagNames' => implode( '|', $tagNames ),
						'weightedTagWeight' => $tagWeights,
						'docIds' => implode( ',', $docIds )
					] );
				$status->error( 'cirrussearch-failed-update-weighted-tags' );
			}
		}
		return $status;
	}

	/**
	 * @param string $indexSuffix
	 * @param string[] $docIds
	 * @param string $tagField
	 * @param string $tagPrefix
	 * @param int $batchSize
	 * @return Status
	 */
	public function sendResetWeightedTags(
		string $indexSuffix,
		array $docIds,
		string $tagField,
		string $tagPrefix,
		int $batchSize = 30
	): Status {
		return $this->sendUpdateWeightedTags(
			$indexSuffix,
			$docIds,
			$tagField,
			$tagPrefix,
			CirrusIndexField::MULTILIST_DELETE_GROUPING,
			null,
			$batchSize
		);
	}

	/**
	 * @param string $indexSuffix suffix of index to which to send $documents
	 * @param \Elastica\Document[] $documents documents to send
	 * @return Status
	 */
	public function sendData( $indexSuffix, array $documents ) {
		if ( !$documents ) {
			return Status::newGood();
		}

		// Copy the docs so that modifications made in this method are not propagated up to the caller
		$docsCopy = [];
		foreach ( $documents as $doc ) {
			$docsCopy[] = clone $doc;
		}
		$documents = $docsCopy;

		// Perform final stage of document building. This only
		// applies to `page` documents, docs built by something
		// other than BuildDocument will pass through unchanged.
		$services = MediaWikiServices::getInstance();
		$builder = new BuildDocument(
			$this->connection,
			$services->getDBLoadBalancer()->getConnection( DB_REPLICA ),
			$services->getParserCache(),
			$services->getRevisionStore(),
			new CirrusSearchHookRunner( $services->getHookContainer() ),
			$services->getBacklinkCacheFactory()
		);
		try {
			foreach ( $documents as $i => $doc ) {
				if ( !$builder->finalize( $doc ) ) {
					// Something has changed while this was hanging out in the job
					// queue and should no longer be written to elastic.
					unset( $documents[$i] );
				}
			}
		} catch ( BuildDocumentException $be ) {
			$this->failedLog->warning(
				'Failed to update documents',
				[ 'exception' => $be ]
			);
			return Status::newFatal( 'cirrussearch-failed-build-document' );
		}

		if ( !$documents ) {
			// All documents noop'd
			return Status::newGood();
		}

		/**
		 * Transform the finalized documents into noop scripts if possible
		 * to reduce update load.
		 */
		if ( $this->searchConfig->getElement( 'CirrusSearchWikimediaExtraPlugin', 'super_detect_noop' ) ) {
			foreach ( $documents as $i => $doc ) {
				// BC Check for jobs that used to contain Document|Script
				if ( $doc instanceof \Elastica\Document ) {
					$documents[$i] = $this->docToSuperDetectNoopScript( $doc );
				}
			}
		}

		foreach ( $documents as $doc ) {
			$doc->setRetryOnConflict( $this->retryOnConflict() );
			// Hints need to be retained until after finalizing
			// the documents and building the noop scripts.
			CirrusIndexField::resetHints( $doc );
		}

		$exception = null;
		$responseSet = null;
		$justDocumentMissing = false;
		try {
			$pageIndex = $this->connection->getIndex( $this->indexBaseName, $indexSuffix );

			$this->start( new BulkUpdateRequestLog(
				$this->connection->getClient(),
				'sending {numBulk} documents to the {index} index(s)',
				'send_data_write',
				[ 'numBulk' => count( $documents ), 'index' => $pageIndex->getName() ]
			) );
			$bulk = new \Elastica\Bulk( $this->connection->getClient() );
			$bulk->setShardTimeout( $this->searchConfig->get( 'CirrusSearchUpdateShardTimeout' ) );
			$bulk->setIndex( $pageIndex );
			if ( $this->searchConfig->getElement( 'CirrusSearchElasticQuirks', 'retry_on_conflict' ) ) {
				$actions = [];
				foreach ( $documents as $doc ) {
					$action = AbstractDocument::create( $doc, 'update' );
					$metadata = $action->getMetadata();
					// Rename deprecated _retry_on_conflict
					// TODO: fix upstream in Elastica.
					if ( isset( $metadata['_retry_on_conflict'] ) ) {
						$metadata['retry_on_conflict'] = $metadata['_retry_on_conflict'];
						unset( $metadata['_retry_on_conflict'] );
						$action->setMetadata( $metadata );
					}
					$actions[] = $action;
				}

				$bulk->addActions( $actions );
			} else {
				$bulk->addData( $documents, 'update' );
			}
			$responseSet = $bulk->send();
		} catch ( ResponseException $e ) {
			$justDocumentMissing = $this->bulkResponseExceptionIsJustDocumentMissing( $e,
				function ( $docId ) use ( $e, $indexSuffix ) {
					$this->log->info(
						"Updating a page that doesn't yet exist in Elasticsearch: {docId}",
						[ 'docId' => $docId, 'indexSuffix' => $indexSuffix ]
					);
				}
			);
			if ( !$justDocumentMissing ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}

		// TODO: rewrite error handling, the logic here is hard to follow
		$validResponse = $responseSet !== null && count( $responseSet->getBulkResponses() ) > 0;
		if ( $exception === null && ( $justDocumentMissing || $validResponse ) ) {
			$this->success();
			if ( $validResponse ) {
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable responseset is not null
				$this->reportUpdateMetrics( $responseSet, $indexSuffix, count( $documents ) );
			}
			return Status::newGood();
		} else {
			$this->failure( $exception );
			$documentIds = array_map( static function ( $d ) {
				return (string)( $d->getId() );
			}, $documents );
			$logContext = [ 'docId' => implode( ', ', $documentIds ) ];
			if ( $exception ) {
				$logContext['exception'] = $exception;
			} else {
				// we want to figure out error_massage from the responseData log, because
				// error_message is currently not set when exception is null and response is not
				// valid
				$responseData = $responseSet->getData();
				$responseDataString = json_encode( $responseData );

				// in logstash some error_message seems to be empty we are assuming its due to
				// non UTF-8 sequences in the response data causing the json_encode to return empty
				// string,so we added a logic to validate the assumption
				if ( json_last_error() === JSON_ERROR_UTF8 ) {
					$responseDataString =
						json_encode( $this->convertEncoding( $responseData ) );
				} elseif ( json_last_error() !== JSON_ERROR_NONE ) {
					$responseDataString = json_last_error_msg();
				}

				$logContext['error_message'] = mb_substr( $responseDataString, 0, 4096 );
			}
			$this->failedLog->warning(
				'Failed to update documents {docId}',
				$logContext
			);
			return Status::newFatal( 'cirrussearch-failed-send-data' );
		}
	}

	/**
	 * @param \Elastica\Bulk\ResponseSet $responseSet
	 * @param string $indexSuffix
	 * @param int $sent
	 */
	private function reportUpdateMetrics(
		\Elastica\Bulk\ResponseSet $responseSet, $indexSuffix, $sent
	) {
		$updateStats = [
			'sent' => $sent,
		];
		$allowedOps = [ 'created', 'updated', 'noop' ];
		foreach ( $responseSet->getBulkResponses() as $bulk ) {
			$opRes = 'unknown';
			if ( $bulk instanceof \Elastica\Bulk\Response ) {
				if ( isset( $bulk->getData()['result'] )
					&& in_array( $bulk->getData()['result'], $allowedOps )
				) {
					$opRes = $bulk->getData()['result'];
				}
			}
			if ( isset( $updateStats[$opRes] ) ) {
				$updateStats[$opRes]++;
			} else {
				$updateStats[$opRes] = 1;
			}
		}
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$cluster = $this->connection->getClusterName();
		$metricsPrefix = "CirrusSearch.$cluster.updates";
		foreach ( $updateStats as $what => $num ) {
			$stats->updateCount(
				"$metricsPrefix.details.{$this->indexBaseName}.$indexSuffix.$what", $num
			);
			$stats->updateCount( "$metricsPrefix.all.$what", $num );
		}
	}

	/**
	 * Send delete requests to Elasticsearch.
	 *
	 * @param string[] $docIds elasticsearch document ids to delete
	 * @param string|null $indexSuffix index from which to delete.  null means all.
	 * @return Status
	 */
	public function sendDeletes( $docIds, $indexSuffix = null ) {
		if ( $indexSuffix === null ) {
			$indexes = $this->connection->getAllIndexSuffixes( Connection::PAGE_DOC_TYPE );
		} else {
			$indexes = [ $indexSuffix ];
		}

		$idCount = count( $docIds );
		if ( $idCount !== 0 ) {
			try {
				foreach ( $indexes as $indexSuffix ) {
					$this->startNewLog(
						'deleting {numIds} from {indexSuffix}',
						'send_deletes', [
							'numIds' => $idCount,
							'indexSuffix' => $indexSuffix,
						]
					);
					$this->connection
						->getIndex( $this->indexBaseName, $indexSuffix )
						->deleteDocuments(
							array_map(
								static function ( $id ) {
									return new Document( $id );
								}, $docIds
							)
						);
					$this->success();
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$this->failure( $e );
				$this->failedLog->warning(
					'Failed to delete documents: {docId}',
					[
						'docId' => implode( ', ', $docIds ),
						'exception' => $e,
					]
				);
				return Status::newFatal( 'cirrussearch-failed-send-deletes' );
			}
		}

		return Status::newGood();
	}

	/**
	 * @param string $localSite The wikiId to add/remove from local_sites_with_dupe
	 * @param string $indexName The name of the index to perform updates to
	 * @param array[] $otherActions A list of arrays each containing the id within elasticsearch
	 *   ('docId') and the article namespace ('ns') and DB key ('dbKey') at the within $localSite
	 * @param int $batchSize number of docs to update in a single bulk
	 * @return Status
	 */
	public function sendOtherIndexUpdates( $localSite, $indexName, array $otherActions, $batchSize = 30 ) {
		$client = $this->connection->getClient();
		$status = Status::newGood();
		foreach ( array_chunk( $otherActions, $batchSize ) as $updates ) {
			'@phan-var array[] $updates';
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
					'super_detect_noop'
				);
				$script->setId( $update['docId'] );
				$script->setParam( '_type', '_doc' );
				$script->setParam( '_index', $indexName );
				$bulk->addScript( $script, 'update' );
				$titles[] = $title;
			}

			// Execute the bulk update
			$exception = null;
			try {
				$this->start( new BulkUpdateRequestLog(
					$this->connection->getClient(),
					'updating {numBulk} documents in other indexes',
					'send_data_other_idx_write',
						[ 'numBulk' => count( $updates ), 'index' => $indexName ]
					) );
				$bulk->send();
			} catch ( ResponseException $e ) {
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
					"OtherIndex update for articles: {titleStr}",
					[ 'exception' => $exception, 'titleStr' => implode( ',', $titles ) ]
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
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$page->loadPageData( WikiPage::READ_LATEST );
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
	protected function bulkResponseExceptionIsJustDocumentMissing(
		ResponseException $exception, $logCallback = null
	) {
		$justDocumentMissing = true;
		foreach ( $exception->getResponseSet()->getBulkResponses() as $bulkResponse ) {
			if ( !$bulkResponse->hasError() ) {
				continue;
			}

			$error = $bulkResponse->getFullError();
			if ( is_string( $error ) ) {
				// es 1.7 cluster
				$message = $bulkResponse->getError();
				if ( strpos( $message, 'DocumentMissingException' ) === false ) {
					$justDocumentMissing = false;
					continue;
				}
			} else {
				// es 2.x cluster
				if ( $error !== null && $error['type'] !== 'document_missing_exception' ) {
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
	 * @param string $description
	 * @param string $queryType
	 * @param string[] $extra
	 * @return SearchRequestLog
	 */
	protected function newLog( $description, $queryType, array $extra = [] ) {
		return new SearchRequestLog(
			$this->connection->getClient(),
			$description,
			$queryType,
			$extra
		);
	}

	/**
	 * Converts a document into a call to super_detect_noop from the wikimedia-extra plugin.
	 * @internal made public for testing purposes
	 * @param \Elastica\Document $doc
	 * @return \Elastica\Script\Script
	 */
	public function docToSuperDetectNoopScript( \Elastica\Document $doc ) {
		$handlers = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
		$params = array_diff_key( $doc->getParams(), [ CirrusIndexField::DOC_HINT_PARAM => 1 ] );

		$params['source'] = $doc->getData();

		if ( $handlers ) {
			Assert::precondition( is_array( $handlers ), "Noop hints must be an array" );
			$params['handlers'] = $handlers;
		} else {
			$params['handlers'] = [];
		}
		$extraHandlers = $this->searchConfig->getElement( 'CirrusSearchWikimediaExtraPlugin', 'super_detect_noop_handlers' );
		if ( is_array( $extraHandlers ) ) {
			$params['handlers'] += $extraHandlers;
		}

		if ( $params['handlers'] === [] ) {
			// The noop script only supports Map but an empty array
			// may be transformed to [] instead of {} when serialized to json
			// causing class cast exception failures
			$params['handlers'] = (object)[];
		}
		$script = new \Elastica\Script\Script( 'super_detect_noop', $params, 'super_detect_noop' );
		if ( $doc->getDocAsUpsert() ) {
			CirrusIndexField::resetHints( $doc );
			$script->setUpsert( $doc );
		}

		return $script;
	}

	/**
	 * @return int Number of times to instruct Elasticsearch to retry updates that fail on
	 *  version conflicts.
	 */
	private function retryOnConflict(): int {
		return $this->searchConfig->get(
			'CirrusSearchUpdateConflictRetryCount' );
	}

	private function convertEncoding( $d ) {
		if ( is_string( $d ) ) {
			return mb_convert_encoding( $d, 'UTF-8', 'UTF-8' );
		}

		foreach ( $d as &$v ) {
			$v = $this->convertEncoding( $v );
		}

		return $d;
	}

}
