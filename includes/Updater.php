<?php

namespace CirrusSearch;
use CirrusSearch\BuildDocument\PageDataBuilder;
use CirrusSearch\BuildDocument\PageTextBuilder;
use \MWTimestamp;
use \ParserCache;
use \ProfileSection;
use \SearchUpdate;
use \Sanitizer;
use \Title;
use \WikiPage;

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

	/**
	 * Full title text of pages updated in this process.  Used for deduplication
	 * of updates.
	 * @var array(String)
	 */
	private $updated = array();

	public function __construct() {
		parent::__construct( null, null );
	}

	/**
	 * Update a single page.
	 * @param Title $title
	 * @param bool $checkFreshness Whether to check page freshness when updating
	 */
	public function updateFromTitle( $title, $checkFreshness = false ) {
		global $wgCirrusSearchUpdateShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		$page = $this->traceRedirects( $title );
		if ( $page ) {
			$this->updatePages( array( $page ), $checkFreshness, $wgCirrusSearchUpdateShardTimeout,
				$wgCirrusSearchClientSideUpdateTimeout, self::INDEX_EVERYTHING );
		}
	}

	/**
	 * Trace redirects from the title to the destination.  Also registers the title in the
	 * memory of titles updated and detects special pages.
	 *
	 * @param Title $title title to trace
	 * @return WikiPage|null wikipage if the $title either isn't a redirect or resolves to
	 *    an updateable page that hasn't been updated yet.  Null if the page has been
	 *    updated, is a special page, or the redirects enter a loop.
	 */
	public function traceRedirects( $title ) {
		// Loop through redirects until we get to the ultimate target
		while ( true ) {
			$titleText = $title->getFullText();
			if ( in_array( $titleText, $this->updated ) ) {
				// Already indexed this article in this process.  This is mostly useful
				// to catch self redirects but has a storied history of catching strange
				// behavior.
				return null;
			}

			// Never. Ever. Index. Negative. Namespaces.
			if ( $title->getNamespace() < 0 ) {
				return null;
			}

			$page = WikiPage::factory( $title );
			if ( !$page->exists() ) {
				wfDebugLog( 'CirrusSearch', "Ignoring an update for a non-existant page: $titleText" );
				return null;
			}
			$content = $page->getContent();
			if ( is_string( $content ) ) {
				$content = new TextContent( $content );
			}

			// Add the page to the list of updated pages before we start trying to update to catch redirect loops.
			$this->updated[] = $titleText;
			if ( $content->isRedirect() ) {
				$target = $content->getUltimateRedirectTarget();
				if ( $target->equals( $page->getTitle() ) ) {
					// This doesn't warn about redirect loops longer than one but we'll catch those anyway.
					wfDebugLog( 'CirrusSearch', "Title redirecting to itself. Skip indexing" );
					return null;
				}
				$title = $target;
				continue;
			} else {
				return $page;
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
	 *
	 * @param $pages array(WikiPage) pages to update
	 * @param $checkFreshness boolean Should we check if Elasticsearch already has
	 *   up to date copy of the document before sending it?
	 * @param $shardTimeout null|string How long should elaticsearch wait for an offline
	 *   shard.  Defaults to null, meaning don't wait.  Null is more efficient when sending
	 *   multiple pages because Cirrus will use Elasticsearch's bulk API.  Timeout is in
	 *   Elasticsearch's time format.
	 * @param $clientSideTimeout null|int timeout in seconds to update pages or null to not
	 *      change the configured timeout which defaults to 300 seconds.
	 * @param $flags int Bitfield containing instructions about how the document should be built
	 *   and sent to Elasticsearch.
	 */
	public function updatePages( $pages, $checkFreshness, $shardTimeout, $clientSideTimeout, $flags ) {
		$profiler = new ProfileSection( __METHOD__ );

		if ( $clientSideTimeout !== null ) {
			Connection::setTimeout( $clientSideTimeout );
		}
		if ( $checkFreshness ) {
			// TODO I bet we can do this with a multi-get
			$freshPages = array();
			foreach ( $pages as $page ) {
				if ( !$this->isFresh( $page ) ) {
					$freshPages[] = $page;
				}
			}
		} else {
			$freshPages = $pages;
		}

		OtherIndexJob::queueIfRequired( $this->pagesToTitles( $pages ), true );

		$allDocuments = array_fill_keys( Connection::getAllIndexTypes(), array() );
		foreach ( $this->buildDocumentsForPages( $freshPages, $flags ) as $document ) {
			$suffix = Connection::getIndexSuffixForNamespace( $document->get( 'namespace' ) );
			$allDocuments[$suffix][] = $document;
		}
		$count = 0;
		foreach( $allDocuments as $indexType => $documents ) {
			$this->sendDocuments( $indexType, $documents, $shardTimeout );
			$count += count( $documents );
		}

		return $count;
	}

	/**
	 * Delete pages from the elasticsearch index.  $titles and $ids must point to the
	 * same pages and should point to them in the same order.
	 *
	 * @param $titles array(Title) of titles to delete
	 * @param $ids array(integer) of ids to delete
	 * @param $clientSideTimeout null|int timeout in seconds to update pages or null to not
	 *      change the configured timeout which defaults to 300 seconds.
	 */
	public function deletePages( $titles, $ids, $clientSideTimeout = null ) {
		$profiler = new ProfileSection( __METHOD__ );

		OtherIndexJob::queueIfRequired( $titles, false );

		if ( $clientSideTimeout !== null ) {
			Connection::setTimeout( $clientSideTimeout );
		}
		$this->sendDeletes( $ids );
	}

	private function isFresh( $page ) {
		$searcher = new Searcher( 0, 0, array( $page->getTitle()->getNamespace(), null ), null );
		$get = $searcher->get( $page->getTitle()->getArticleId(), array( 'timestamp ') );
		if ( !$get->isOk() ) {
			return false;
		}
		$get = $get->getValue();
		if ( $get === null ) {
			return false;
		}
		$found = new MWTimestamp( $get->timestamp );
		$diff = $found->diff( new MWTimestamp( $page->getTimestamp() ) );
		if ( $diff === false ) {
			return false;
		}
		return !$diff->invert;
	}

	/**
	 * @param string $indexType type of index to which to send $documents
	 * @param array $documents documents to send
	 * @param null|string $shardTimeout How long should elaticsearch wait for an offline
	 *   shard.  Defaults to null, meaning don't wait.  Null is more efficient when sending
	 *   multiple pages because Cirrus will use Elasticsearch's bulk API.  Timeout is in
	 *   Elasticsearch's time format.
	 */
	private function sendDocuments( $indexType, $documents, $shardTimeout ) {
		$documentCount = count( $documents );
		if ( $documentCount === 0 ) {
			return;
		}

		$profiler = new ProfileSection( __METHOD__ );

		$exception = null;
		try {
			$pageType = Connection::getPageType( wfWikiId(), $indexType );
			$this->start( "sending $documentCount documents to the $indexType index" );
			// addDocuments (notice plural) is the bulk api
			$bulk = new \Elastica\Bulk( Connection::getClient() );
			if ( $shardTimeout ) {
				$bulk->setShardTimeout( $shardTimeout );
			}
			$bulk->setType( $pageType );
			$bulk->addDocuments( $documents );
			$bulk->send();
		} catch ( \Elastica\Exception\Bulk\ResponseException $e ) {
			if ( !$this->bulkResponseExceptionIsJustDocumentMissing( $e,
					"Updating a page that doesn't yet exist in Elasticsearch" ) ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}
		if ( $exception === null ) {
			$this->success();
		} else {
			$this->failure( $exception );
			$documentIds = array_map( function( $doc ) {
				return $doc->getId();
			}, $documents );
			wfDebugLog( 'CirrusSearchChangeFailed', 'Update for doc ids: ' .
				implode( ',', $documentIds ) );
		}
	}

	private function buildDocumentsForPages( $pages, $flags ) {
		$profiler = new ProfileSection( __METHOD__ );

		$indexOnSkip = $flags & self::INDEX_ON_SKIP;
		$skipParse = $flags & self::SKIP_PARSE;
		$skipLinks = $flags & self::SKIP_LINKS;
		$fullDocument = !( $skipParse || $skipLinks );

		$documents = array();
		foreach ( $pages as $page ) {
			wfProfileIn( __METHOD__ . '-basic' );
			$title = $page->getTitle();
			if ( !$page->exists() ) {
				wfLogWarning( 'Attempted to build a document for a page that doesn\'t exist.  This should be caught ' .
					"earlier but wasn't.  Page: $title" );
				wfProfileOut( __METHOD__ . '-basic' );
				continue;	
			}

			$doc = new \Elastica\Document( $page->getId(), array(
				'namespace' => $title->getNamespace(),
				'namespace_text' => $title->getPageLanguage()->getFormattedNsText(
						$title->getNamespace() ),
				'title' => $title->getText(),
				'timestamp' => wfTimestamp( TS_ISO_8601, $page->getTimestamp() ),
			) );
			// Everything as sent as an update to prevent overwriting fields maintained in other processes like
			// addLocalSiteToOtherIndex and removeLocalSiteFromOtherIndex.
			$doc->setOpType( 'update' );
			// But we need a way to index documents that don't already exist.  We're willing to upsert any full
			// documents or any documents that we've been explicitly told it is ok to index when they aren't full.
			// This is typically just done during the first phase of the initial index build.
			// A quick note about docAsUpsert's merging behavior:  It overwrites all fields provided by doc unless they
			// are objects in both doc and the indexed source.  We're ok with this because all of our fields are either
			// regular types or lists of objects and lists are overwritten.
			$doc->setDocAsUpsert( $fullDocument || $indexOnSkip );
			$documents[] = $doc;
			wfProfileOut( __METHOD__ . '-basic' );

			if ( !$skipParse ) {
				wfProfileIn( __METHOD__ . '-parse' );

				// Get text to index, based on content and parser output
				list( $content, $parserOutput ) = $this->getContentAndParserOutput( $page );

				// Build our page data
				$pageBuilder = new PageDataBuilder( $doc, $title, $content, $parserOutput );
				$doc = $pageBuilder->build();

				// And build the page text itself
				$textBuilder = new PageTextBuilder( $doc, $content, $parserOutput );
				$doc = $textBuilder->build();

				// Then let hooks have a go
				wfRunHooks( 'CirrusSearchBuildDocumentParse', array( $doc, $title, $content, $parserOutput ) );

				wfProfileOut( __METHOD__ . '-parse' );
			}

			if ( !$skipLinks ) {
				wfProfileIn( __METHOD__ . '-links' );
				wfRunHooks( 'CirrusSearchBuildDocumentLinks', array( $doc, $title ) );
				wfProfileOut( __METHOD__ . '-links' );
			}
		}

		wfProfileIn( __METHOD__ . '-finish-batch' );
		wfRunHooks( 'CirrusSearchBuildDocumentFinishBatch', array( $pages ) );
		wfProfileOut( __METHOD__ . '-finish-batch' );

		return $documents;
	}

	/**
	 * Fetch page's content and parser output, using the parser cache if we can
	 *
	 * @param WikiPage $page The wikipage to get output for
	 * @return array(Content,ParserOutput)
	 */
	private function getContentAndParserOutput( $page ) {
		$content = $page->getContent();
		$parserOptions = $page->makeParserOptions( 'canonical' );
		$parserOutput = ParserCache::singleton()->get( $page, $parserOptions );
		if ( !$parserOutput ) {
			// We specify the revision ID here. There might be a newer revision,
			// but we don't care because (a) we've already got a job somewhere
			// in the queue to index it, and (b) we want magic words like
			// {{REVISIONUSER}} to be accurate
			$revId = $page->getRevision()->getId();
			$parserOutput = $content->getParserOutput( $page->getTitle(), $revId );
		}
		return array( $content, $parserOutput );
	}

	/**
	 * Update the search index for articles linked from this article.  Just updates link counts.
	 * @param $addedLinks array of Titles added to the page
	 * @param $removedLinks array of Titles removed from the page
	 */
	public function updateLinkedArticles( $addedLinks, $removedLinks ) {
		global $wgCirrusSearchUpdateShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		// We don't do anything different with removed or added pages at this point so merge them.
		$titles = array_merge( $addedLinks, $removedLinks );
		$pages = array();
		foreach ( $titles as $title ) {
			$page = WikiPage::factory( $title );
			if ( $page === null || !$page->exists() ) {
				// Skip link to non-existant page.
				continue;
			}
			// Resolve one level of redirects because only one level of redirects is scored.
			if ( $page->isRedirect() ) {
				$target = $page->getRedirectTarget();
				$page = new WikiPage( $target );
				if ( !$page->exists() ) {
					// Skip redirects to non-existant pages
					continue;
				}
			}
			if ( $page->isRedirect() ) {
				// This is a redirect to a redirect which doesn't count in the search score any way.
				continue;
			}
			if ( in_array( $page->getId(), $this->updated ) ) {
				// We've already updated this page in this proces so there is no need to update it again.
				continue;
			}
			// Note that we don't add this page to the list of updated pages because this update isn't
			// a full update (just link counts).
			$pages[] = $page;
		}
		$this->updatePages( $pages, false, $wgCirrusSearchUpdateShardTimeout, $wgCirrusSearchClientSideUpdateTimeout,
			self::SKIP_PARSE );
	}

	/**
	 * Check if $exception is a bulk response exception that just contains document is missing failures.
	 *
	 * @param \Elastica\Exception\Bulk\ResponseException $exception exception to check
	 * @param string|null $log debug message to log if this happens or null to log nothing
	 */
	protected function bulkResponseExceptionIsJustDocumentMissing( $exception, $log ) {
		$justDocumentMissing = true;
		foreach ( $exception->getResponseSet()->getBulkResponses() as $bulkResponse ) {
			if ( $bulkResponse->hasError() ) {
				if ( strpos( $bulkResponse->getError(), 'DocumentMissingException' ) === false ) {
					$justDocumentMissing = false;
				} else {
					// This is generally not an error but we should log it to see how many we get
					if ( $log ) {
						$id = $bulkResponse->getAction()->getDocument()->getId();
						wfDebugLog( 'CirrusSearch', $log . ":  $id" );
					}
				}
			}
		}
		return $justDocumentMissing;
	}

	/**
	 * Send delete requests to Elasticsearch.
	 *
	 * @param array(int) $ids ids to delete from Elasticsearch
	 */
	private function sendDeletes( $ids ) {
		$profiler = new ProfileSection( __METHOD__ );

		$idCount = count( $ids );
		if ( $idCount === 0 ) {
			return;
		}

		try {
			foreach ( Connection::getAllIndexTypes() as $type ) {
				$this->start( "deleting $idCount from $type" );
				Connection::getPageType( wfWikiId(), $type )->deleteIds( $ids );
				$this->success();
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$this->failure( $e );
			wfDebugLog( 'CirrusSearchChangeFailed', 'Delete for ids: ' .
				implode( ',', $ids ) );
		}
	}

	/**
	 * Convert an array of pages to an array of their titles.
	 *
	 * @param $pages array(WikiPage)
	 * @return array(Title)
	 */
	private function pagesToTitles( $pages ) {
		$titles = array();
		foreach ( $pages as $page ) {
			$titles[] = $page->getTitle();
		}
		return $titles;
	}
}
