<?php

namespace CirrusSearch;

use CirrusSearch\BuildDocument\FileDataBuilder;
use CirrusSearch\BuildDocument\PageDataBuilder;
use CirrusSearch\BuildDocument\PageTextBuilder;
use Hooks as MWHooks;
use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MWTimestamp;
use ParserCache;
use ParserOutput;
use Sanitizer;
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

	/**
	 * Full title text of pages updated in this process.  Used for deduplication
	 * of updates.
	 * @var array(String)
	 */
	private $updated = array();

	/**
	 * @var string|null Name of cluster to write to, or null if none
	 */
	protected $writeToClusterName;

	public function __construct( Connection $conn, array $flags = array() ) {
		parent::__construct( $conn, null, null );
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
		global $wgCirrusSearchUpdateShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		list( $page, $redirects ) = $this->traceRedirects( $title );
		if ( $page ) {
			$updatedCount = $this->updatePages(
				array( $page ),
				$wgCirrusSearchUpdateShardTimeout,
				$wgCirrusSearchClientSideUpdateTimeout,
				self::INDEX_EVERYTHING
			);
			if ( $updatedCount < 0 ) {
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
			$logger = LoggerFactory::getInstance( 'CirrusSearch' );
			if ( !$page->exists() ) {
				$logger->debug( "Ignoring an update for a nonexistent page: $titleText" );
				return array( null, $redirects );
			}
			$content = $page->getContent();
			if ( is_string( $content ) ) {
				$content = new TextContent( $content );
			}
			// If the event that the content is _still_ not usable, we have to give up.
			if ( !is_object( $content ) ) {
				return array( null, $redirects );
			}

			// Add the page to the list of updated pages before we start trying to update to catch redirect loops.
			$this->updated[] = $titleText;
			if ( $content->isRedirect() ) {
				$redirects[] = $page;
				$target = $content->getUltimateRedirectTarget();
				if ( $target->equals( $page->getTitle() ) ) {
					// This doesn't warn about redirect loops longer than one but we'll catch those anyway.
					$logger->info( "Title redirecting to itself. Skip indexing" );
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
	 * @return int Number of documents updated of -1 if there was an error
	 */
	public function updatePages( $pages, $shardTimeout, $clientSideTimeout, $flags ) {
		global $wgCirrusSearchWikimediaExtraPlugin;

		// Don't update the same page twice. We shouldn't, but meh
		$pageIds = array();
		$pages = array_filter( $pages, function( $page ) use ( &$pageIds ) {
			if ( !in_array( $page->getId(), $pageIds ) ) {
				$pageIds[] = $page->getId();
				return true;
			}
			return false;
		} );

		$titles = $this->pagesToTitles( $pages );
		Job\OtherIndex::queueIfRequired( $titles, $this->writeToClusterName );

		$allData = array_fill_keys( $this->connection->getAllIndexTypes(), array() );
		foreach ( $this->buildDocumentsForPages( $pages, $flags ) as $document ) {
			$suffix = $this->connection->getIndexSuffixForNamespace( $document->get( 'namespace' ) );
			if ( isset( $wgCirrusSearchWikimediaExtraPlugin[ 'super_detect_noop' ] ) &&
					$wgCirrusSearchWikimediaExtraPlugin[ 'super_detect_noop' ] ) {
				$document = $this->docToSuperDetectNoopScript( $document );
			}
			$allData[$suffix][] = $document;
		}
		$count = 0;
		foreach( $allData as $indexType => $data ) {
			// Elasticsearch has a queue capacity of 50 so if $data contains 50 pages it could bump up against
			// the max.  So we chunk it and do them sequentially.
			foreach( array_chunk( $data, 10 ) as $chunked ) {
				$job = new Job\ElasticaWrite(
					reset( $titles ),
					array(
						'clientSideTimeout' => $clientSideTimeout,
						'method' => 'sendData',
						'arguments' => array( $indexType, $chunked, $shardTimeout ),
						'cluster' => $this->writeToClusterName,
					)
				);
				// This job type will insert itself into the job queue
				// with a delay if writes to ES are currently unavailable
				$job->run();
			}
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
		Job\OtherIndex::queueIfRequired( $titles, $this->writeToClusterName );
		$job = new Job\ElasticaWrite(
			reset( $titles ),
			array(
				'clientSideTimeout' => $clientSideTimeout,
				'method' => 'sendDeletes',
				'arguments' => array( $ids, $indexType ),
				'cluster' => $this->writeToClusterName,
			)
		);
		// This job type will insert itself into the job queue
		// with a delay if writes to ES are currently paused
		$job->run();
	}

	/**
	 * @param \WikiPage[] $pages
	 * @param int $flags
	 */
	private function buildDocumentsForPages( $pages, $flags ) {
		global $wgCirrusSearchUpdateConflictRetryCount;

		$indexOnSkip = $flags & self::INDEX_ON_SKIP;
		$skipParse = $flags & self::SKIP_PARSE;
		$skipLinks = $flags & self::SKIP_LINKS;
		$forceParse = $flags & self::FORCE_PARSE;
		$fullDocument = !( $skipParse || $skipLinks );

		$documents = array();
		foreach ( $pages as $page ) {
			$title = $page->getTitle();
			if ( !$page->exists() ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Attempted to build a document for a page that doesn\'t exist.  This should be caught ' .
					"earlier but wasn't.  Page: {title}",
					array( 'title' => $title )
				);
				continue;
			}

			$doc = new \Elastica\Document( $page->getId(), array(
				'version' => $page->getLatest(),
				'version_type' => 'external',
				'namespace' => $title->getNamespace(),
				'namespace_text' => Util::getNamespaceText( $title ),
				'title' => $title->getText(),
				'timestamp' => wfTimestamp( TS_ISO_8601, $page->getTimestamp() ),
			) );
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
				// Get text to index, based on content and parser output
				list( $content, $parserOutput ) = $this->getContentAndParserOutput(
					$page,
					$forceParse
				);

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
				MWHooks::run( 'CirrusSearchBuildDocumentParse', array( $doc, $title, $content, $parserOutput, $this->connection ) );
			}

			if ( !$skipLinks ) {
				MWHooks::run( 'CirrusSearchBuildDocumentLinks', array( $doc, $title, $this->connection) );
			}

			$documents[] = $doc;
		}

		MWHooks::run( 'CirrusSearchBuildDocumentFinishBatch', array( $pages ) );

		return $documents;
	}

	/**
	 * Converts a document into a call to super_detect_noop from the wikimedia-extra plugin.
	 */
	private function docToSuperDetectNoopScript( $doc ) {
		$params = $doc->getParams();
		$params[ 'source' ] = $doc->getData();
		$params[ 'detectors' ] = array(
			'incoming_links' => 'within 20%',
		);

		$script = new \Elastica\Script( 'super_detect_noop', $params, 'native' );
		if ( $doc->getDocAsUpsert() ) {
			$script->setUpsert( $doc );
		}

		return $script;
	}

	/**
	 * Fetch page's content and parser output, using the parser cache if we can
	 *
	 * @param WikiPage $page The wikipage to get output for
	 * @param int $forceParse Bypass ParserCache and force a fresh parse.
	 * @return array(Content,ParserOutput)
	 */
	private function getContentAndParserOutput( $page, $forceParse ) {
		$content = $page->getContent();
		$parserOptions = $page->makeParserOptions( 'canonical' );

		if ( !$forceParse ) {
			$parserOutput = ParserCache::singleton()->get( $page, $parserOptions );
		}

		if ( !isset( $parserOutput ) || !$parserOutput instanceof ParserOutput ) {
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
	 * Update the search index for newly linked or unlinked articles.
	 * @param array $titles titles to update
	 * @return boolean were all pages updated?
	 */
	public function updateLinkedArticles( $titles ) {
		global $wgCirrusSearchUpdateShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		$pages = array();
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
				// We've already updated this page in this proces so there is no need to update it again.
				continue;
			}
			// Note that we don't add this page to the list of updated pages because this update isn't
			// a full update (just link counts).
			$pages[] = $page;
		}
		$updatedCount = $this->updatePages( $pages, $wgCirrusSearchUpdateShardTimeout,
			$wgCirrusSearchClientSideUpdateTimeout, self::SKIP_PARSE );
		return $updatedCount >= 0;
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
