<?php

namespace CirrusSearch;

use CirrusSearch\BuildDocument\BuildDocument;
use CirrusSearch\BuildDocument\BuildDocumentException;
use CirrusSearch\BuildDocument\DocumentSizeLimiter;
use CirrusSearch\Extra\MultiList\MultiListBuilder;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\CirrusIndexField;
use CirrusSearch\Wikimedia\WeightedTagsHooks;
use Elastica\Bulk\Action\AbstractDocument;
use Elastica\Document;
use Elastica\Exception\Bulk\ResponseException;
use Elastica\Exception\RuntimeException;
use Elastica\JSON;
use Elastica\Response;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use Wikimedia\Assert\Assert;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Stats\StatsFactory;

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

	private StatsFactory $stats;
	/**
	 * @var DocumentSizeLimiter
	 */
	private $docSizeLimiter;

	/**
	 * @param Connection $conn
	 * @param SearchConfig $config
	 * @param StatsFactory|null $stats A StatsFactory (already prefixed with the right component)
	 * @param DocumentSizeLimiter|null $docSizeLimiter
	 */
	public function __construct(
		Connection $conn,
		SearchConfig $config,
		?StatsFactory $stats = null,
		?DocumentSizeLimiter $docSizeLimiter = null
	) {
		parent::__construct( $conn, null, 0 );
		$this->stats = $stats ?? Util::getStatsFactory();
		$this->log = LoggerFactory::getInstance( 'CirrusSearch' );
		$this->failedLog = LoggerFactory::getInstance( 'CirrusSearchChangeFailed' );
		$this->indexBaseName = $config->get( SearchConfig::INDEX_BASE_NAME );
		$this->searchConfig = $config;
		$this->docSizeLimiter = $docSizeLimiter ?? new DocumentSizeLimiter(
			$config->getProfileService()->loadProfile( SearchProfileService::DOCUMENT_SIZE_LIMITER ) );
	}

	/**
	 * @deprecated use {@link sendWeightedTagsUpdate} instead.
	 */
	public function sendUpdateWeightedTags(
		string $indexSuffix,
		array $docIds,
		string $tagField,
		string $tagPrefix,
		$tagNames = null,
		?array $tagWeights = null,
		int $batchSize = 30
	): Status {
		return $this->sendWeightedTagsUpdate(
			$indexSuffix,
			$tagPrefix,
			is_array( $tagWeights ) ? array_reduce(
				$docIds, static function ( $docTagsWeights, $docId ) use ( $tagNames, $tagWeights ) {
					if ( array_key_exists( $docId, $tagWeights ) ) {
						$docTagsWeights[$docId] = MultiListBuilder::buildTagWeightsFromLegacyParameters(
						$tagNames,
						$tagWeights[$docId]
						);
					}

					return $docTagsWeights;
				}, []
			) : array_fill_keys( $docIds, MultiListBuilder::buildTagWeightsFromLegacyParameters( $tagNames ) ),
			$batchSize
		);
	}

	/**
	 * @param string $indexSuffix
	 * @param string $tagPrefix
	 * @param int[][]|null[][] $tagWeights a map of `[ docId: string => [ tagName: string => tagWeight: int|null ] ]`
	 * @param int $batchSize
	 *
	 * @return Status
	 */
	public function sendWeightedTagsUpdate(
		string $indexSuffix,
		string $tagPrefix,
		array $tagWeights,
		int $batchSize = 30
	): Status {
		$client = $this->connection->getClient();
		$status = Status::newGood();
		$pageIndex = $this->connection->getIndex( $this->indexBaseName, $indexSuffix );
		foreach ( array_chunk( array_keys( $tagWeights ), $batchSize ) as $docIdsChunk ) {
			$bulk = new \Elastica\Bulk( $client );
			$bulk->setIndex( $pageIndex );
			foreach ( $docIdsChunk as $docId ) {
				$docTags = MultiListBuilder::buildWeightedTags(
					$tagPrefix,
					$tagWeights[$docId],
				);
				$script = new \Elastica\Script\Script( 'super_detect_noop', [
					'source' => [
						WeightedTagsHooks::FIELD_NAME => array_map( static fn ( $docTag ) => (string)$docTag,
							$docTags )
					],
					'handlers' => [ WeightedTagsHooks::FIELD_NAME => CirrusIndexField::MULTILIST_HANDLER ],
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
				$this->start(
					new BulkUpdateRequestLog(
						$this->connection->getClient(),
						'updating {numBulk} documents',
						'send_data_reset_weighted_tags',
						[
							'numBulk' => count( $docIdsChunk ),
							'index' => $pageIndex->getName()
						]
					)
				);
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
					"Update weighted tag {weightedTagFieldName} for {weightedTagPrefix} in articles: {docIds}", [
						'exception' => $exception,
						'weightedTagFieldName' => WeightedTagsHooks::FIELD_NAME,
						'weightedTagPrefix' => $tagPrefix,
						'weightedTagNames' => implode(
							'|',
							array_reduce( $tagWeights, static function ( $tagNames, $docTagWeights ) {
								return array_unique( array_merge( $tagNames, array_keys( $docTagWeights ) ) );
							} )
						),
						'weightedTagWeight' => var_export( $tagWeights, true ),
						'docIds' => implode( ',', array_keys( $tagWeights ) )
					]
				);
			}
		}

		return $status;
	}

	/**
	 * @deprecated use {@link sendWeightedTagsUpdate} instead.
	 */
	public function sendResetWeightedTags(
		string $indexSuffix,
		array $docIds,
		string $tagField,
		string $tagPrefix,
		int $batchSize = 30
	): Status {
		return $this->sendWeightedTagsUpdate(
			$indexSuffix,
			$tagPrefix,
			array_fill_keys( $docIds, [ CirrusIndexField::MULTILIST_DELETE_GROUPING => null ] ),
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
			$services->getConnectionProvider()->getReplicaDatabase(),
			$services->getRevisionStore(),
			$services->getBacklinkCacheFactory(),
			$this->docSizeLimiter,
			$services->getTitleFormatter(),
			$services->getWikiPageFactory()
		);
		try {
			foreach ( $documents as $i => $doc ) {
				if ( !$builder->finalize( $doc ) ) {
					// Something has changed while this was hanging out in the job
					// queue and should no longer be written to elastic.
					unset( $documents[$i] );
				}
				$this->reportDocSize( $doc );
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
			$exception = $e;
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}

		if ( $justDocumentMissing ) {
			// wa have a failure but this is just docs that are missing in the index
			// missing docs are logged above
			$this->success();
			return Status::newGood();
		}
		// check if the response is valid by making sure that it has bulk responses
		if ( $responseSet !== null && count( $responseSet->getBulkResponses() ) > 0 ) {
			$this->success();
			$this->reportUpdateMetrics( $responseSet, $indexSuffix, count( $documents ) );
			return Status::newGood();
		}
		// Everything else should be a failure.
		if ( $exception === null ) {
			// Elastica failed to identify the error, reason is that the Elastica Bulk\Response
			// does identify errors only in individual responses if the request fails without
			// getting a formal elastic response Bulk\Response->isOk might remain true
			// So here we construct the ResponseException that should have been built and thrown
			// by Elastica
			$lastRequest = $this->connection->getClient()->getLastRequest();
			if ( $lastRequest !== null ) {
				$exception = new \Elastica\Exception\ResponseException( $lastRequest,
					new Response( $responseSet->getData() ) );
			} else {
				$exception = new RuntimeException( "Unknown error in bulk request (Client::getLastRequest() is null)" );
			}
		}
		$this->failure( $exception );
		$documentIds = array_map( static function ( $d ) {
			return (string)( $d->getId() );
		}, $documents );
		$this->failedLog->warning(
			'Failed to update documents {docId}',
			[
				'docId' => implode( ', ', $documentIds ),
				'exception' => $exception
			]
		);
		return Status::newFatal( 'cirrussearch-failed-send-data' );
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
		$cluster = $this->connection->getClusterName();
		$metricsPrefix = "CirrusSearch.$cluster.updates";
		foreach ( $updateStats as $what => $num ) {
			$this->stats->getCounter( "update_total" )
				->setLabel( "status", $what )
				->setLabel( "search_cluster", $cluster )
				->setLabel( "index_name", $indexSuffix )
				->copyToStatsdAt( [
					"$metricsPrefix.details.{$this->indexBaseName}.$indexSuffix.$what",
					"$metricsPrefix.all.$what"
				] )
				->incrementBy( $num );
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
		$page->loadPageData( IDBAccessObject::READ_LATEST );
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
	 * @param \Elastica\Document $doc
	 * @return \Elastica\Script\Script
	 * @internal made public for testing purposes
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

	private function reportDocSize( Document $doc ): void {
		$cluster = $this->connection->getClusterName();
		try {
			// Use the same JSON output that Elastica uses, it might not be the options MW uses
			// to populate event-gate (esp. regarding escaping UTF-8) but hopefully it's close
			// to what we will be using.
			$len = strlen( JSON::stringify( $doc->getData(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES ) );
			// Use a timing stat as we'd like to have percentiles calculated (possibly use T348796 once available)
			// note that prior to switching to prometheus we used to have min and max, if that's proven to be still useful
			// to track abnormally large docs we might consider another approach (log a warning?)
			$this->stats->getTiming( "update_doc_size_kb" )
				->setLabel( "search_cluster", $cluster )
				->copyToStatsdAt( "CirrusSearch.$cluster.updates.all.doc_size" )
				->observe( $len );

		} catch ( \JsonException $e ) {
			$this->log->warning( "Cannot estimate CirrusSearch doc size", [ "exception" => $e ] );
		}
	}

}
