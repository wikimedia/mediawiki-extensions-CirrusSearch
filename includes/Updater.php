<?php

namespace CirrusSearch;

use Hooks as MWHooks;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use CirrusSearch\Search\CirrusIndexField;
use TextContent;
use Title;
use WikiPage;

/**
 * Performs updates and deletes on the Elasticsearch index.  Called by
 * CirrusSearch.php (our SearchEngine implementation), forceSearchIndex
 * (for bulk updates), and CirrusSearch's jobs.
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
class Updater extends ElasticsearchIntermediary {
	// Bit field parameters for updatePages et al.
	const INDEX_EVERYTHING = 0;
	const INDEX_ON_SKIP = 1;
	const SKIP_PARSE = 2;
	const SKIP_LINKS = 4;
	const FORCE_PARSE = 8;
	const INSTANT_INDEX = 16;

	/**
	 * Full title text of pages updated in this process.  Used for deduplication
	 * of updates.
	 * @var string[]
	 */
	private $updated = [];

	/**
	 * @var string|null Name of cluster to write to, or null if none
	 */
	protected $writeToClusterName;

	/**
	 * @var SearchConfig
	 */
	protected $searchConfig;

	/**
	 * @param Connection $conn
	 * @param SearchConfig $config
	 * @param string[] $flags
	 */
	public function __construct( Connection $conn, SearchConfig $config, array $flags = [] ) {
		parent::__construct( $conn, null, 0 );
		$this->searchConfig = $config;
		if ( in_array( 'same-cluster', $flags ) ) {
			$this->writeToClusterName = $this->connection->getClusterName();
		}
	}

	/**
	 * Update a single page.
	 * @param Title $title
	 * @return bool true if the page updated, false if it failed, null if it didn't need updating
	 */
	public function updateFromTitle( $title ) {
		list( $page, $redirects ) = $this->traceRedirects( $title );
		if ( $page ) {
			$updatedCount = $this->updatePages(
				[ $page ],
				self::INDEX_EVERYTHING
			);
			if ( $updatedCount < 0 ) {
				return false;
			}
		}

		if ( count( $redirects ) === 0 ) {
			return true;
		}
		$redirectDocIds = [];
		foreach ( $redirects as $redirect ) {
			$redirectDocIds[] = $this->searchConfig->makeId( $redirect->getId() );
		}
		return $this->deletePages( [], $redirectDocIds );
	}

	/**
	 * Trace redirects from the title to the destination.  Also registers the title in the
	 * memory of titles updated and detects special pages.
	 *
	 * @param Title $title title to trace
	 * @return array(target redirects)
	 *    - target is WikiPage|null wikipage if the $title either isn't a redirect or resolves
	 *    to an updatable page that hasn't been updated yet.  Null if the page has been
	 *    updated, is a special page, or the redirects enter a loop.
	 *    - redirects is an array of WikiPages, one per redirect in the chain.  If title isn't
	 *    a redirect then this will be an empty array
	 */
	public function traceRedirects( $title ) {
		// Loop through redirects until we get to the ultimate target
		$redirects = [];
		while ( true ) {
			$titleText = $title->getFullText();
			if ( in_array( $titleText, $this->updated ) ) {
				// Already indexed this article in this process.  This is mostly useful
				// to catch self redirects but has a storied history of catching strange
				// behavior.
				return [ null, $redirects ];
			}

			// Never. Ever. Index. Negative. Namespaces.
			if ( $title->getNamespace() < 0 ) {
				return [ null, $redirects ];
			}

			$page = WikiPage::factory( $title );
			$logger = LoggerFactory::getInstance( 'CirrusSearch' );
			if ( !$page->exists() ) {
				$logger->debug( "Ignoring an update for a nonexistent page: $titleText" );
				return [ null, $redirects ];
			}
			$content = $page->getContent();
			if ( is_string( $content ) ) {
				$content = new TextContent( (string)$content );
			}
			// If the event that the content is _still_ not usable, we have to give up.
			if ( !is_object( $content ) ) {
				return [ null, $redirects ];
			}

			// Add the page to the list of updated pages before we start trying to update to catch redirect loops.
			$this->updated[] = $titleText;
			if ( $content->isRedirect() ) {
				$redirects[] = $page;
				$target = $content->getUltimateRedirectTarget();
				if ( $target->equals( $page->getTitle() ) ) {
					// This doesn't warn about redirect loops longer than one but we'll catch those anyway.
					$logger->info( "Title redirecting to itself. Skip indexing" );
					return [ null, $redirects ];
				}
				$title = $target;
				continue;
			} else {
				return [ $page, $redirects ];
			}
		}
	}

	/**
	 * This updates pages in elasticsearch.
	 *
	 * $flags includes:
	 *   INDEX_EVERYTHING Cirrus will parse the page and count the links and send the document
	 *     to Elasticsearch as an index so if it doesn't exist it'll be created.
	 *   SKIP_PARSE Cirrus will skip parsing the page when building the document.  It makes
	 *     sense to do this when you know the page hasn't changed like when it is newly linked
	 *     from another page.
	 *   SKIP_LINKS Cirrus will skip collecting links information.  It makes sense to do this
	 *     when you know the link counts aren't yet available like during the first phase of
	 *     the two phase index build.
	 *   INDEX_ON_SKIP Cirrus will send an update if SKIP_PARSE or SKIP_LINKS rather than an
	 *     index.  Indexing with any portion of the document skipped is dangerous because it
	 *     can put half created pages in the index.  This is only a good idea during the first
	 *     half of the two phase index build.
	 *   INSTANT_INDEX Do quick index of initial data, without waiting. Do not retry the job
	 *     if it failed. This is useful for fast-index updates which can later be picked up by
	 *     main update if they fail.
	 *
	 * @param WikiPage[] $pages pages to update
	 * @param int $flags Bit field containing instructions about how the document should be built
	 *   and sent to Elasticsearch.
	 * @return int Number of documents updated of -1 if there was an error
	 */
	public function updatePages( $pages, $flags ) {
		// Don't update the same page twice. We shouldn't, but meh
		$pageIds = [];
		$pages = array_filter( $pages, function ( WikiPage $page ) use ( &$pageIds ) {
			if ( !in_array( $page->getId(), $pageIds ) ) {
				$pageIds[] = $page->getId();
				return true;
			}
			return false;
		} );
		$isInstantIndex = ( $flags & self::INSTANT_INDEX ) !== 0;

		$titles = $this->pagesToTitles( $pages );
		if ( !$isInstantIndex ) {
			Job\OtherIndex::queueIfRequired( $titles, $this->writeToClusterName );
		}

		$allDocuments = array_fill_keys( $this->connection->getAllIndexTypes(), [] );
		foreach ( $this->buildDocumentsForPages( $pages, $flags ) as $document ) {
			$suffix = $this->connection->getIndexSuffixForNamespace( $document->get( 'namespace' ) );
			$allDocuments[$suffix][] = $document;
		}

		$count = 0;
		foreach ( $allDocuments as $indexType => $documents ) {
			// Elasticsearch has a queue capacity of 50 so if $documents contains 50 pages it could bump up against
			// the max.  So we chunk it and do them sequentially.
			foreach ( array_chunk( $documents, 10 ) as $chunked ) {
				$job = Job\ElasticaWrite::build(
					reset( $titles ),
					'sendData',
					[ $indexType, $chunked ],
					[
						'cluster' => $this->writeToClusterName,
						'doNotRetry' => $isInstantIndex,
					]
				);
				// This job type will insert itself into the job queue
				// with a delay if writes to ES are currently unavailable
				$job->run();
			}
			$count += count( $documents );
		}

		return $count;
	}

	/**
	 * Delete pages from the elasticsearch index.  $titles and $docIds must point to the
	 * same pages and should point to them in the same order.
	 *
	 * @param Title[] $titles List of titles to delete.  If empty then skipped other index
	 *      maintenance is skipped.
	 * @param int[]|string[] $docIds List of elasticsearch document ids to delete
	 * @param string|null $indexType index from which to delete.  null means all.
	 * @param string $elasticType Mapping type to use for the document
	 * @return bool Always returns true.
	 */
	public function deletePages( $titles, $docIds, $indexType = null, $elasticType = null ) {
		Job\OtherIndex::queueIfRequired( $titles, $this->writeToClusterName );
		$job = Job\ElasticaWrite::build(
			$titles ? reset( $titles ) : Title::makeTitle( NS_SPECIAL, "Badtitle/" . Job\ElasticaWrite::class ),
			'sendDeletes',
			[ $docIds, $indexType, $elasticType ],
			[ 'cluster' => $this->writeToClusterName ]
		);
		// This job type will insert itself into the job queue
		// with a delay if writes to ES are currently paused
		$job->run();

		return true;
	}

	/**
	 * Add documents to archive index.
	 * @param array $archived
	 * @param bool $forceIndex If true, index to archive regardless of config.
	 * @return bool
	 */
	public function archivePages( $archived, $forceIndex = false ) {
		if ( !$this->searchConfig->getElement( 'CirrusSearchIndexDeletes' ) && !$forceIndex ) {
			// Disabled by config - don't do anything
			return true;
		}
		$docs = $this->buildArchiveDocuments( $archived );
		$head = reset( $archived );
		foreach ( array_chunk( $docs, 10 ) as $chunked ) {
			$job = Job\ElasticaWrite::build(
				$head['title'],
				'sendData',
				[ Connection::GENERAL_INDEX_TYPE, $chunked, Connection::ARCHIVE_TYPE_NAME ],
				[ 'cluster' => $this->writeToClusterName ]
			);
			$job->run();
		}

		return true;
	}

	/**
	 * Build Elastica documents for archived pages.
	 * @param array $archived
	 * @return \Elastica\Document[]
	 */
	private function buildArchiveDocuments( array $archived ) {
		$docs = [];
		foreach ( $archived as $delete ) {
			if ( !isset( $delete['title'] ) ) {
				// These come from pages that still exist, but are redirects.
				// This is non-obvious and we probably need a better way...
				continue;
			}
			/** @var Title $title */
			$title = $delete['title'];
			$doc = new \Elastica\Document( $delete['page'], [
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'wiki' => wfWikiID(),
			] );
			$doc->setDocAsUpsert( true );
			$doc->setRetryOnConflict( $this->searchConfig->getElement( 'CirrusSearchUpdateConflictRetryCount' ) );

			$docs[] = $doc;
		}

		return $docs;
	}
	/**
	 * @param \WikiPage[] $pages
	 * @param int $flags
	 * @return \Elastica\Document[]
	 */
	private function buildDocumentsForPages( $pages, $flags ) {
		global $wgCirrusSearchUpdateConflictRetryCount;

		$indexOnSkip = $flags & self::INDEX_ON_SKIP;
		$skipParse = $flags & self::SKIP_PARSE;
		$skipLinks = $flags & self::SKIP_LINKS;
		$forceParse = $flags & self::FORCE_PARSE;
		$fullDocument = !( $skipParse || $skipLinks );

		$documents = [];
		$engine = new \CirrusSearch();
		foreach ( $pages as $page ) {
			$title = $page->getTitle();
			if ( !$page->exists() ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Attempted to build a document for a page that doesn\'t exist.  This should be caught ' .
					"earlier but wasn't.  Page: {title}",
					[ 'title' => $title ]
				);
				continue;
			}

			$doc = new \Elastica\Document( $this->searchConfig->makeId( $page->getId() ), [
				'version' => $page->getLatest(),
				'wiki' => wfWikiID(),
				'namespace' => $title->getNamespace(),
				'namespace_text' => Util::getNamespaceText( $title ),
				'title' => $title->getText(),
				'timestamp' => wfTimestamp( TS_ISO_8601, $page->getTimestamp() ),
			] );
			CirrusIndexField::addNoopHandler( $doc, 'version', 'documentVersion' );
			// Everything as sent as an update to prevent overwriting fields maintained in other processes like
			// OtherIndex::updateOtherIndex.
			// But we need a way to index documents that don't already exist.  We're willing to upsert any full
			// documents or any documents that we've been explicitly told it is ok to index when they aren't full.
			// This is typically just done during the first phase of the initial index build.
			// A quick note about docAsUpsert's merging behavior:  It overwrites all fields provided by doc unless they
			// are objects in both doc and the indexed source.  We're ok with this because all of our fields are either
			// regular types or lists of objects and lists are overwritten.
			$doc->setDocAsUpsert( $fullDocument || $indexOnSkip );
			$doc->setRetryOnConflict( $wgCirrusSearchUpdateConflictRetryCount );

			if ( !$skipParse ) {
				$contentHandler = $page->getContentHandler();
				$parserCache = $forceParse ? null : MediaWikiServices::getInstance()->getParserCache();
				$output = $contentHandler->getParserOutputForIndexing( $page, $parserCache );

				$fieldDefinitions = $contentHandler->getFieldsForSearchIndex( $engine );
				foreach ( $contentHandler->getDataForSearchIndex( $page, $output, $engine ) as
					$field => $fieldData ) {
					$doc->set( $field, $fieldData );
					if ( isset( $fieldDefinitions[$field] ) ) {
						$hints = $fieldDefinitions[$field]->getEngineHints( $engine );
						CirrusIndexField::addIndexingHints( $doc, $field, $hints );
					}
				}

				// Then let hooks have a go
				MWHooks::run( 'CirrusSearchBuildDocumentParse', [
					$doc,
					$title,
					$page->getContent(),
					$output,
					$this->connection
				] );
			}

			if ( !$skipLinks ) {
				MWHooks::run( 'CirrusSearchBuildDocumentLinks', [ $doc, $title, $this->connection ] );
			}

			$documents[] = $doc;
		}

		MWHooks::run( 'CirrusSearchBuildDocumentFinishBatch', [ $pages ] );

		return $documents;
	}

	/**
	 * Update the search index for newly linked or unlinked articles.
	 * @param Title[] $titles titles to update
	 * @return bool were all pages updated?
	 */
	public function updateLinkedArticles( $titles ) {
		$pages = [];
		foreach ( $titles as $title ) {
			// Special pages don't get updated
			if ( !$title || $title->getNamespace() < 0 ) {
				continue;
			}

			$page = WikiPage::factory( $title );
			if ( $page === null || !$page->exists() ) {
				// Skip link to nonexistent page.
				continue;
			}
			// Resolve one level of redirects because only one level of redirects is scored.
			if ( $page->isRedirect() ) {
				$target = $page->getRedirectTarget();
				$page = new WikiPage( $target );
				if ( !$page->exists() ) {
					// Skip redirects to nonexistent pages
					continue;
				}
			}
			if ( $page->isRedirect() ) {
				// This is a redirect to a redirect which doesn't count in the search score any way.
				continue;
			}
			if ( in_array( $title->getFullText(), $this->updated ) ) {
				// We've already updated this page in this process so there is no need to update it again.
				continue;
			}
			// Note that we don't add this page to the list of updated pages because this update isn't
			// a full update (just link counts).
			$pages[] = $page;
		}
		$updatedCount = $this->updatePages( $pages, self::SKIP_PARSE );
		return $updatedCount >= 0;
	}

	/**
	 * Convert an array of pages to an array of their titles.
	 *
	 * @param WikiPage[] $pages
	 * @return Title[]
	 */
	private function pagesToTitles( $pages ) {
		$titles = [];
		foreach ( $pages as $page ) {
			$titles[] = $page->getTitle();
		}
		return $titles;
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
}
