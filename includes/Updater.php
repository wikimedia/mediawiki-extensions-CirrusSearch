<?php

namespace CirrusSearch;
use CirrusSearch\BuildDocument\FileDataBuilder;
use CirrusSearch\BuildDocument\PageDataBuilder;
use CirrusSearch\BuildDocument\PageTextBuilder;
use \MWTimestamp;
use \ParserCache;
use \ProfileSection;
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
	 * @return bool true if the page updated, false if it failed, null if it didn't need updating
	 */
	public function updateFromTitle( $title ) {
		global $wgCirrusSearchUpdateShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		list( $page, $redirects ) = $this->traceRedirects( $title );
		if ( $page ) {
			$success = (bool)$this->updatePages(
				array( $page ),
				$wgCirrusSearchUpdateShardTimeout,
				$wgCirrusSearchClientSideUpdateTimeout,
				self::INDEX_EVERYTHING
			);
			if ( !$success ) {
				return false;
			}
		}

		if ( count( $redirects ) === 0 ) {
			return true;
		}
		$redirectIds = array();
		foreach ( $redirects as $redirect ) {
			$redirectIds[] = $redirect->getId();
		}
		return $this->deletePages( array(), $redirectIds, $wgCirrusSearchClientSideUpdateTimeout );
	}

	/**
	 * Trace redirects from the title to the destination.  Also registers the title in the
	 * memory of titles updated and detects special pages.
	 *
	 * @param Title $title title to trace
	 * @return array(target, redirects)
	 *    - target is WikiPage|null wikipage if the $title either isn't a redirect or resolves
	 *    to an updateable page that hasn't been updated yet.  Null if the page has been
	 *    updated, is a special page, or the redirects enter a loop.
	 *    - redirects is an array of WikiPages, one per redirect in the chain.  If title isn't
	 *    a redirect then this will be an empty array
	 */
	public function traceRedirects( $title ) {
		// Loop through redirects until we get to the ultimate target
		$redirects = array();
		while ( true ) {
			$titleText = $title->getFullText();
			if ( in_array( $titleText, $this->updated ) ) {
				// Already indexed this article in this process.  This is mostly useful
				// to catch self redirects but has a storied history of catching strange
				// behavior.
				return array( null, $redirects );
			}

			// Never. Ever. Index. Negative. Namespaces.
			if ( $title->getNamespace() < 0 ) {
				return array( null, $redirects );
			}

			$page = WikiPage::factory( $title );
			if ( !$page->exists() ) {
				wfDebugLog( 'CirrusSearch', "Ignoring an update for a nonexistent page: $titleText" );
				return array( null, $redirects );
			}
			$content = $page->getContent();
			if ( is_string( $content ) ) {
				$content = new TextContent( $content );
			}

			// Add the page to the list of updated pages before we start trying to update to catch redirect loops.
			$this->updated[] = $titleText;
			if ( $content->isRedirect() ) {
				$redirects[] = $page;
				$target = $content->getUltimateRedirectTarget();
				if ( $target->equals( $page->getTitle() ) ) {
					// This doesn't warn about redirect loops longer than one but we'll catch those anyway.
					wfDebugLog( 'CirrusSearch', "Title redirecting to itself. Skip indexing" );
					return array( null, $redirects );
				}
				$title = $target;
				continue;
			} else {
				return array( $page, $redirects );
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
	 * @param $shardTimeout null|string How long should elaticsearch wait for an offline
	 *   shard.  Defaults to null, meaning don't wait.  Null is more efficient when sending
	 *   multiple pages because Cirrus will use Elasticsearch's bulk API.  Timeout is in
	 *   Elasticsearch's time format.
	 * @param $clientSideTimeout null|int timeout in seconds to update pages or null to not
	 *      change the configured timeout which defaults to 300 seconds.
	 * @param $flags int Bitfield containing instructions about how the document should be built
	 *   and sent to Elasticsearch.
	 * @return int Number of documents updated
	 */
	public function updatePages( $pages, $shardTimeout, $clientSideTimeout, $flags ) {
		$profiler = new ProfileSection( __METHOD__ );

		// Don't update the same page twice. We shouldn't, but meh
		$pageIds = array();
		$pages = array_filter( $pages, function( $page ) use ( &$pageIds ) {
			if ( !in_array( $page->getId(), $pageIds ) ) {
				$pageIds[] = $page->getId();
				return true;
			}
			return false;
		} );

		if ( $clientSideTimeout !== null ) {
			Connection::setTimeout( $clientSideTimeout );
		}

		Job\OtherIndex::queueIfRequired( $this->pagesToTitles( $pages ), true );

		$allData = array_fill_keys( Connection::getAllIndexTypes(), array() );
		foreach ( $this->buildDocumentsForPages( $pages, $flags ) as $document ) {
			$suffix = Connection::getIndexSuffixForNamespace( $document->get( 'namespace' ) );
			// $allData[$suffix][] = $this->docToScript( $document );
			// To quickly switch back to sending doc as upsert instead of script, remove the line above
			// and switch to the one below:
			// -- As you can see we switched back - MVEL was freaking out every once in a while and we
			// had trouble reproducing it locally.  Faster to just turn it off.
			$allData[$suffix][] = $document;
		}
		$count = 0;
		foreach( $allData as $indexType => $data ) {
			$this->sendData( $indexType, $data, $shardTimeout );
			$count += count( $data );
		}

		return $count;
	}

	/**
	 * Delete pages from the elasticsearch index.  $titles and $ids must point to the
	 * same pages and should point to them in the same order.
	 *
	 * @param $titles array(Title) of titles to delete.  If empty then skipped other index
	 *      maintenance is skipped.
	 * @param $ids array(integer) of ids to delete
	 * @param $clientSideTimeout null|int timeout in seconds to update pages or null to not
	 *      change the configured timeout which defaults to 300 seconds.
	 * @param string $indexType index from which to delete
	 * @return bool True if nothing happened or we successfully deleted, false on failure
	 */
	public function deletePages( $titles, $ids, $clientSideTimeout = null, $indexType = null ) {
		$profiler = new ProfileSection( __METHOD__ );

		Job\OtherIndex::queueIfRequired( $titles, false );

		if ( $clientSideTimeout !== null ) {
			Connection::setTimeout( $clientSideTimeout );
		}
		return $this->sendDeletes( $ids, $indexType );
	}

	/**
	 * @param string $indexType type of index to which to send $data
	 * @param array(\Elastica\Script or \Elastica\Document) $data documents to send
	 * @param null|string $shardTimeout How long should elaticsearch wait for an offline
	 *   shard.  Defaults to null, meaning don't wait.  Null is more efficient when sending
	 *   multiple pages because Cirrus will use Elasticsearch's bulk API.  Timeout is in
	 *   Elasticsearch's time format.
	 * @return bool True if nothing happened or we successfully indexed, false on failure
	 */
	private function sendData( $indexType, $data, $shardTimeout ) {
		$documentCount = count( $data );
		if ( $documentCount === 0 ) {
			return true;
		}

		$profiler = new ProfileSection( __METHOD__ );

		$exception = null;
		try {
			$pageType = Connection::getPageType( wfWikiId(), $indexType );
			$this->start( "sending $documentCount documents to the $indexType index" );
			$bulk = new \Elastica\Bulk( Connection::getClient() );
			if ( $shardTimeout ) {
				$bulk->setShardTimeout( $shardTimeout );
			}
			$bulk->setType( $pageType );
			$bulk->addData( $data, 'update' );
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
			return true;
		} else {
			$this->failure( $exception );
			$documentIds = array_map( function( $d ) {
				return $d->getId();
			}, $data );
			wfDebugLog( 'CirrusSearchChangeFailed', 'Update for doc ids: ' .
				implode( ',', $documentIds ) . '; error message was: ' . $exception->getMessage() );
			return false;
		}
	}

	private function buildDocumentsForPages( $pages, $flags ) {
		global $wgCirrusSearchUpdateConflictRetryCount;

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
				'namespace_text' => Util::getNamespaceText( $title ),
				'title' => $title->getText(),
				'timestamp' => wfTimestamp( TS_ISO_8601, $page->getTimestamp() ),
			) );
			// Everything as sent as an update to prevent overwriting fields maintained in other processes like
			// addLocalSiteToOtherIndex and removeLocalSiteFromOtherIndex.
			// But we need a way to index documents that don't already exist.  We're willing to upsert any full
			// documents or any documents that we've been explicitly told it is ok to index when they aren't full.
			// This is typically just done during the first phase of the initial index build.
			// A quick note about docAsUpsert's merging behavior:  It overwrites all fields provided by doc unless they
			// are objects in both doc and the indexed source.  We're ok with this because all of our fields are either
			// regular types or lists of objects and lists are overwritten.
			$doc->setDocAsUpsert( $fullDocument || $indexOnSkip );
			$doc->setRetryOnConflict( $wgCirrusSearchUpdateConflictRetryCount );
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

				// If we're a file, build its metadata too
				if ( $title->getNamespace() === NS_FILE ) {
					$fileBuilder = new FileDataBuilder( $doc, $title );
					$doc = $fileBuilder->build();
				}

				// Then let hooks have a go
				wfRunHooks( 'CirrusSearchBuildDocumentParse', array( $doc, $title, $content, $parserOutput ) );

				wfProfileOut( __METHOD__ . '-parse' );
			}

			if ( !$skipLinks ) {
				wfRunHooks( 'CirrusSearchBuildDocumentLinks', array( $doc, $title ) );
			}

			$documents[] = $doc;
		}

		wfRunHooks( 'CirrusSearchBuildDocumentFinishBatch', array( $pages ) );

		return $documents;
	}

	private function docToScript( $doc ) {
		$scriptText = <<<MVEL
changed = false;

MVEL;
		$params = $doc->getParams();
		foreach ( $doc->getData() as $key => $value ) {
			$scriptText .= <<<MVEL
if ( ctx._source.$key != $key ) {
	changed = true;
	ctx._source.$key = $key;
}

MVEL;
			$params[ $key ] = $value;
		}
		$scriptText .= <<<MVEL
if ( !changed ) {
	ctx.op = "none";
}

MVEL;
		$script = new \Elastica\Script( $scriptText, $params, 'mvel' );
		if ( $doc->getDocAsUpsert() ) {
			$script->setUpsert( $doc );
		}

		return $script;
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
		$titleKeys = array_merge( $addedLinks, $removedLinks );
		$pages = array();
		foreach ( $titleKeys as $titleKey ) {
			$title = Title::newFromDBKey( $titleKey );
			if ( !$title ) {
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
				// We've already updated this page in this proces so there is no need to update it again.
				continue;
			}
			// Note that we don't add this page to the list of updated pages because this update isn't
			// a full update (just link counts).
			$pages[] = $page;
		}
		$this->updatePages( $pages, $wgCirrusSearchUpdateShardTimeout, $wgCirrusSearchClientSideUpdateTimeout,
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
						$id = $bulkResponse->getAction()->getData()->getId();
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
	 * @param string|null $indexType index from which to delete.  null means all.
	 * @return bool True if nothing happened or we deleted, false on failure
	 */
	private function sendDeletes( $ids, $indexType = null ) {
		$profiler = new ProfileSection( __METHOD__ );

		$idCount = count( $ids );
		if ( $idCount !== 0 ) {
			try {
				if ( $indexType === null ) {
					foreach ( Connection::getAllIndexTypes() as $indexType ) {
						$this->start( "deleting $idCount from $indexType" );
						Connection::getPageType( wfWikiId(), $indexType )->deleteIds( $ids );
						$this->success();
					}
				} else {
					$this->start( "deleting $idCount from $indexType" );
					Connection::getPageType( wfWikiId(), $indexType )->deleteIds( $ids );
					$this->success();
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$this->failure( $e );
				wfDebugLog( 'CirrusSearchChangeFailed', 'Delete for ids: ' .
					implode( ',', $ids ) . '; error message was: ' . $e->getMessage() );
				return false;
			}
		}

		return true;
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
