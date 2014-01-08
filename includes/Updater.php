<?php

namespace CirrusSearch;
use \Category;
use \MWTimestamp;
use \ParserCache;
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
class Updater {
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
	private static $updated = array();

	/**
	 * Headings to ignore.  Lazily initialized.
	 * @var array(String)|null
	 */
	private static $ignoredHeadings = null;

	/**
	 * Update a single page.
	 * @param Title $title
	 */
	public static function updateFromTitle( $title ) {
		global $wgCirrusSearchShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		$page = self::traceRedirects( $title );
		if ( $page ) {
			self::updatePages( array( $page ), false, $wgCirrusSearchShardTimeout,
				$wgCirrusSearchClientSideUpdateTimeout, self::INDEX_EVERYTHING );
		}
	}

	/**
	 * Trace redirects from the title to the destination.  Also registers the title in the
	 * memory of titles updated and detects special pages.
	 * @param Title $title title to trace
	 * @return WikiPage|null wikipage if the $title either isn't a redirect or resolves to
	 *    an updateable page that hasn't been updated yet.  Null if the page has been
	 *    updated, is a special page, or the redirects enter a loop.
	 */
	public static function traceRedirects( $title ) {
		// Loop through redirects until we get to the ultimate target
		while ( true ) {
			$titleText = $title->getFullText();
			if ( in_array( $titleText, self::$updated ) ) {
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
			self::$updated[] = $titleText;
			if ( $content->isRedirect() ) {
				$target = $content->getUltimateRedirectTarget();
				if ( $target->equals( $page->getTitle() ) ) {
					// This doesn't warn about redirect loops longer than one but we'll catch those anyway.
					wfDebugLog( 'CirrusSearch', "Title redirecting to itself. Skip indexing" );
					return null;
				}
				wfDebugLog( 'CirrusSearch', "Updating search index for $title which is a redirect to " . $target->getText() );
				$title = $target;
				continue;
			} else {
				return $page;
			}
		}
	}

	/**
	 * Clear the array of already updated/seen pages that is used for deduplication and redirect
	 * chain detection to free memory.
	 */
	public static function clearUpdated() {
		self::$updated = array();
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
	 * @param $clientSideTimeout null|int Timeout in seconds to update pages or null if using
	 *   the Elastica default which is 300 seconds.
	 * @param $flags int Bitfield containing instructions about how the document should be built
	 *   and sent to Elasticsearch.
	 */
	public static function updatePages( $pages, $checkFreshness, $shardTimeout, $clientSideTimeout, $flags ) {
		wfProfileIn( __METHOD__ );

		if ( $clientSideTimeout !== null ) {
			Connection::setTimeout( $clientSideTimeout );
		}
		if ( $checkFreshness ) {
			// TODO I bet we can do this with a multi-get
			$freshPages = array();
			foreach ( $pages as $page ) {
				if ( !self::isFresh( $page ) ) {
					$freshPages[] = $page;
				}
			}
		} else {
			$freshPages = $pages;
		}

		OtherIndexJob::queueIfRequired( self::pagesToTitles( $pages ), true );

		$allDocuments = array_fill_keys( Connection::getAllIndexTypes(), array() );
		foreach ( self::buildDocumentForPages( $freshPages, $flags ) as $document ) {
			$suffix = Connection::getIndexSuffixForNamespace( $document->get( 'namespace' ) );
			$allDocuments[$suffix][] = $document;
		}
		$count = 0;
		foreach( $allDocuments as $indexType => $documents ) {
			self::sendDocuments( $indexType, $documents, $shardTimeout );
			$count += count( $documents );
		}

		wfProfileOut( __METHOD__ );
		return $count;
	}

	/**
	 * Delete pages from the elasticsearch index.  $titles and $ids must point to the
	 * same pages and should point to them in the same order.
	 *
	 * @param $titles array(Title) of titles to delete
	 * @param $ids array(integer) of ids to delete
	 * @param $clientSideTimeout timeout in seconds to update pages or null if using
	 *   the Elastica default which is 300 seconds.
	 */
	public static function deletePages( $titles, $ids, $clientSideTimeout = null ) {
		wfProfileIn( __METHOD__ );

		OtherIndexJob::queueIfRequired( $titles, false );

		if ( $clientSideTimeout !== null ) {
			Connection::setTimeout( $clientSideTimeout );
		}
		self::sendDeletes( $ids );

		wfProfileOut( __METHOD__ );
	}

	private static function isFresh( $page ) {
		$searcher = new Searcher( 0, 0, array( $page->getTitle()->getNamespace() ) );
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
	private static function sendDocuments( $indexType, $documents, $shardTimeout = null ) {
		wfProfileIn( __METHOD__ );

		$documentCount = count( $documents );
		if ( $documentCount === 0 ) {
			return;
		}

		$exception = null;
		try {
			$pageType = Connection::getPageType( wfWikiId(), $indexType );
			// The bulk api doesn't support shardTimeout so don't use it if one is set
			if ( $shardTimeout === null ) {
				$start = microtime( true );
				wfDebugLog( 'CirrusSearch', "Sending $documentCount documents to the $indexType index via bulk api." );
				// addDocuments (notice plural) is the bulk api
				$pageType->addDocuments( $documents );
				$took = round( ( microtime( true ) - $start ) * 1000 );
				wfDebugLog( 'CirrusSearch', "Update completed in $took millis" );
			} else {
				foreach ( $documents as $document ) {
					$start = microtime( true );
					// Logging a fully qualified title here makes for easier debugging
					$title = $document->get( 'namespace' ) . ':' . $document->get( 'title' );
					wfDebugLog( 'CirrusSearch', "Sending $title to the $indexType index." );
					$document->setTimeout( $shardTimeout );
					// The bulk api automatically performs an update if the opType is update but the index api
					// doesn't so we have to make the switch ourselves
					if ( $document->getOpType() === 'update' ) {
						$pageType->updateDocument( $document );
					} else {
						// addDocument (notice singular) is the non-bulk index api
						$pageType->addDocument( $document );
					}
					$took = round( ( microtime( true ) - $start ) * 1000 );
					wfDebugLog( 'CirrusSearch', "Update completed in $took millis" );
				}
			}
		} catch ( \Elastica\Exception\Bulk\ResponseException $e ) {
			if ( self::bulkResponseExceptionIsJustDocumentMissing( $e,
					"Updating a page that doesn't yet exist in Elasticsearch" ) ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}
		if ( $exception !== null ) {
			wfLogWarning( 'CirrusSearch update failed caused by:  ' . $e->getMessage() );
			foreach ( $documents as $document ) {
				wfDebugLog( 'CirrusSearchChangeFailed', 'Update: ' . $document->getId() );
			}
		}

		wfProfileOut( __METHOD__ );
	}

	private static function buildDocumentForPages( $pages, $flags ) {
		global $wgCirrusSearchIndexedRedirects;
		wfProfileIn( __METHOD__ );

		$indexOnSkip = $flags & self::INDEX_ON_SKIP;
		$skipParse = $flags & self::SKIP_PARSE;
		$skipLinks = $flags & self::SKIP_LINKS;
		$fullDocument = !( $skipParse || $skipLinks );

		$documents = array();
		$linkCountClosures = array();
		$linkCountMultiSearch = new \Elastica\Multi\Search( Connection::getClient() );
		foreach ( $pages as $page ) {
			wfProfileIn( __METHOD__ . '-basic' );
			$title = $page->getTitle();
			if ( !$page->exists() ) {
				wfLogWarning( 'Attempted to build a document for a page that doesn\'t exist.  This should be caught ' .
					"earlier but wasn't.  Page: $title" );
				continue;
				wfProfileOut( __METHOD__ . '-basic' );
			}

			$doc = new \Elastica\Document( $page->getId(), array(
				'namespace' => $title->getNamespace(),
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
				list( $content, $parserOutput ) = self::getContentAndParserOutput( $page );
				$text = self::buildTextToIndex( $content, $parserOutput );

				$doc->add( 'text', $text );
				$doc->add( 'text_bytes', strlen( $text ) );
				$doc->add( 'text_words', str_word_count( $text ) ); // TODO remove once text.word_count is available everywhere

				// Index PDF or DJVU text as well
				if ( $title->getNamespace() == NS_FILE ) {
					$file = wfLocalFile( $title );
					if ( $file && $file->exists() && $file->getHandler() ) {
						$fileText = $file->getHandler()->getEntireText( $file );
						if ( $fileText ) {
							$doc->add( 'file_text', $fileText );
						}
					}
				}

				$categories = array();
				foreach ( $parserOutput->getCategories() as $key => $value ) {
					$category = Category::newFromName( $key );
					$categories[] = $category->getTitle()->getText();
				}
				$doc->add( 'category', $categories );

				$templates = array();
				foreach ( $parserOutput->getTemplates() as $tNS => $templatesInNS ) {
					foreach ( $templatesInNS as $tDbKey => $unused ) {
						$templateTitle = Title::makeTitleSafe( $tNS, $tDbKey );
						if ( $templateTitle && $templateTitle->exists() ) {
							$templates[] = $templateTitle->getPrefixedText();
						}
					}
				}
				$doc->add( 'template', $templates );

				$headings = array();
				$ignoredHeadings = self::getIgnoredHeadings();
				foreach ( $parserOutput->getSections() as $heading ) {
					$heading = $heading[ 'line' ];
					// Strip tags from the heading or else we'll display them (escaped) in search results
					$heading = Sanitizer::stripAllTags( $heading );
					// Note that we don't take the level of the heading into account - all headings are equal.
					// Except the ones we ignore.
					if ( !in_array( $heading, $ignoredHeadings ) ) {
						$headings[] = $heading;
					}
				}
				$doc->add( 'heading', $headings );

				$outgoingLinks = array();
				foreach ( $parserOutput->getLinks() as $linkedNamespace => $namespaceLinks ) {
					foreach ( $namespaceLinks as $linkedDbKey => $ignored ) {
						$linked = Title::makeTitle( $linkedNamespace, $linkedDbKey );
						$outgoingLinks[] = $linked->getPrefixedDBKey();
					}
				}
				$doc->add( 'outgoing_link', $outgoingLinks );

				$doc->add( 'external_link', array_keys( $parserOutput->getExternalLinks() ) );

				wfProfileOut( __METHOD__ . '-parse' );
			}

			if ( !$skipLinks ) {
				wfProfileIn( __METHOD__ . '-redirects' );
				// Handle redirects to this page
				$redirectTitles = $title->getBacklinkCache()
					->getLinks( 'redirect', false, false, $wgCirrusSearchIndexedRedirects );
				$redirects = array();
				$redirectPrefixedDBKeys = array();
				// $redirectLinks = 0;
				foreach ( $redirectTitles as $redirect ) {
					// If the redirect is in main or the same namespace as the article the index it
					if ( $redirect->getNamespace() === NS_MAIN && $redirect->getNamespace() === $title->getNamespace()) {
						$redirects[] = array(
							'namespace' => $redirect->getNamespace(),
							'title' => $redirect->getText()
						);
						$redirectPrefixedDBKeys[] = $redirect->getPrefixedDBKey();
					}
				}
				$doc->add( 'redirect', $redirects );

				// Count links
				// Incoming links is the sum of the number of linked pages which we count in Elasticsearch
				// and the number of incoming redirects of which we have a handy list so we count that here.
				$linkCountMultiSearch->addSearch( self::buildCount(
					new \Elastica\Filter\Term( array( 'outgoing_link' => $title->getPrefixedDBKey() ) ) ) );
				$redirectCount = count( $redirects );
				$linkCountClosures[] = function ( $count ) use( $doc, $redirectCount ) {
					$doc->add( 'incoming_links', $count + $redirectCount );
				};
				// If a page doesn't have any redirects then count the links to them.
				if ( count( $redirectPrefixedDBKeys ) ) {
					$linkCountMultiSearch->addSearch( self::buildCount(
						new \Elastica\Filter\Terms( 'outgoing_link', $redirectPrefixedDBKeys ) ) );
					$linkCountClosures[] = function ( $count ) use( $doc ) {
						$doc->add( 'incoming_redirect_links', $count );
					};
				} else {
					$doc->add( 'incoming_redirect_links', 0 );
				}
				wfProfileOut( __METHOD__ . '-redirects' );
			}
		}

		wfProfileIn( __METHOD__ . '-link-counts' );
		$linkCountClosureCount = count( $linkCountClosures );
		if ( !$skipLinks && $linkCountClosureCount ) {
			try {
				$start = microtime( true );
				$result = $linkCountMultiSearch->search();
				$took = round( ( microtime( true ) - $start ) * 1000 );
				$pageCount = count( $pages );
				wfDebugLog( 'CirrusSearch', "Counted links to $pageCount pages in $took millis." );
				for ( $index = 0; $index < $linkCountClosureCount; $index++ ) {
					$linkCountClosures[ $index ]( $result[ $index ]->getTotalHits() );
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				// Note that we still return the pages and execute the update here, we just complain
				wfLogWarning( "Search backend error during link count.  Error message is:  " .
					$e->getMessage() );
				foreach ( $pages as $page ) {
					$id = $page->getId();
					wfDebugLog( 'CirrusSearchChangeFailed', "Links:  $id" );
				}
			}
		}
		wfProfileIn( __METHOD__ . '-link-counts' );
		wfProfileOut( __METHOD__ );
		return $documents;
	}

	/**
	 * Fetch page's content and parser output, using the parser cache if we can
	 *
	 * @param WikiPage $page The wikipage to get output for
	 * @return array(Content,ParserOutput)
	 */
	private static function getContentAndParserOutput( $page ) {
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
	 * Fetch text to index.  If $content is wikitext then render and clean it.  Otherwise delegate
	 * to the $content itself and then to SearchUpdate::updateText to clean the result.
	 * @param $content Content of page
	 * @param $parserOutput ParserOutput from page
	 */
	private static function buildTextToIndex( $content, $parserOutput ) {
		switch ( $content->getModel() ) {
		case CONTENT_MODEL_WIKITEXT:
			return TextFormatter::formatWikitext( $parserOutput );
		default:
			return SearchUpdate::updateText( $content->getTextForSearchIndex() );
		}
	}

	private static function buildCount( $filter ) {
		$type = Connection::getPageType( wfWikiId() );
		$search = new \Elastica\Search( $type->getIndex()->getClient() );
		$search->addIndex( $type->getIndex() );
		$search->addType( $type );
		$search->setOption( \Elastica\Search::OPTION_SEARCH_TYPE,
			\Elastica\Search::OPTION_SEARCH_TYPE_COUNT );
		$matchAll = new \Elastica\Query\MatchAll();
		$search->setQuery( new \Elastica\Query\Filtered( $matchAll, $filter ) );
		return $search;
	}

	private static function getIgnoredHeadings() {
		if ( self::$ignoredHeadings === null ) {
			$source = wfMessage( 'cirrussearch-ignored-headings' )->inContentLanguage();
			if( $source->isDisabled() ) {
				self::$ignoredHeadings = array();
			} else {
				$lines = explode( "\n", $source->plain() );
				$lines = preg_replace( '/#.*$/', '', $lines ); // Remove comments
				$lines = array_map( 'trim', $lines );          // Remove extra spaces
				$lines = array_filter( $lines );               // Remove empty lines
				self::$ignoredHeadings = $lines;               // Now we just have headings!
			}
		}
		return self::$ignoredHeadings;
	}

	/**
	 * Update the search index for articles linked from this article.  Just updates link counts.
	 * @param $addedLinks array of Titles added to the page
	 * @param $removedLinks array of Titles removed from the page
	 */
	public static function updateLinkedArticles( $addedLinks, $removedLinks ) {
		global $wgCirrusSearchShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		// We don't do anything different with removed or added pages at this point so merge them.
		$titles = array_merge( $addedLinks, $removedLinks );
		$pages = array();
		foreach ( $titles as $title ) {
			wfDebugLog( 'CirrusSearch', "Updating link counts for $title" );
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
			if ( in_array( $page->getId(), self::$updated ) ) {
				// We've already updated this page in this proces so there is no need to update it again.
				continue;
			}
			// Note that we don't add this page to the list of updated pages because this update isn't
			// a full update (just link counts).
			$pages[] = $page;
		}
		self::updatePages( $pages, false, $wgCirrusSearchShardTimeout, $wgCirrusSearchClientSideUpdateTimeout,
			self::SKIP_PARSE );
	}

	/**
	 * Add the local wiki to the duplicate tracking list on the indexes of other wikis for $titles.
	 * @param Array(Title) $titles titles for which to add to the tracking list
	 */
	public static function addLocalSiteToOtherIndex( $titles ) {
		// Script is in MVEL and is run in a context with local_site set to this wiki's name
		$script  = <<<MVEL
			if (!ctx._source.containsKey("local_sites_with_dupe")) {
				ctx._source.local_sites_with_dupe = [local_site]
			} else if (ctx._source.local_sites_with_dupe.contains(local_site)) {
				ctx.op = "none"
			} else {
				ctx._source.local_sites_with_dupe += local_site
			}
MVEL;
		self::updateOtherIndex( 'addLocalSite', $script, $titles );
	}

	/**
	 * Remove the local wiki from the duplicate tracking list on the indexes of other wikis for $titles.
	 * @param array(Title) $titles titles for which to remove the tracking field
	 */
	public static function removeLocalSiteFromOtherIndex( $titles ) {
		// Script is in MVEL and is run in a context with local_site set to this wiki's name
		$script  = <<<MVEL
			if (!ctx._source.containsKey("local_sites_with_dupe")) {
				ctx.op = "none"
			} else if (!ctx._source.local_sites_with_dupe.remove(local_site)) {
				ctx.op = "none"
			}
MVEL;
		self::updateOtherIndex( 'removeLocalSite', $script, $titles );
	}

	/**
	 * Update the indexes for other wiki that also store information about $titles.
	 * @param string $actionName name of the action to report in logging
	 * @param string $scriptSource MVEL source script for performing the update
	 * @param array(Title) $titles titles in other indexes to update
	 */
	private static function updateOtherIndex( $actionName, $scriptSource, $titles ) {
		$client = Connection::getClient();
		$bulk = new \Elastica\Bulk( $client );
		$updatesInBulk = 0;
		$localSite = wfWikiId();

		// Build multisearch to find ids to update
		$findIdsMultiSearch = new \Elastica\Multi\Search( Connection::getClient() );
		$findIdsClosures = array();
		foreach ( $titles as $title ) {
			foreach ( OtherIndexes::getExternalIndexes( $title ) as $otherIndex ) {
				if ( $otherIndex === null ) {
					continue;
				}
				$type = Connection::getPageType( $otherIndex );
				$bool = new \Elastica\Filter\Bool();
				// Note that we need to use the keyword indexing of title so the analyzer gets out of the way.
				$bool->addMust( new \Elastica\Filter\Term( array( 'title.keyword' => $title->getText() ) ) );
				$bool->addMust( new \Elastica\Filter\Term( array( 'namespace' => $title->getNamespace() ) ) );
				$filtered = new \Elastica\Query\Filtered( new \Elastica\Query\MatchAll(), $bool );
				$query = new \Elastica\Query( $filtered );
				$query->setFields( array() ); // We only need the _id so don't load the _source
				$query->setSize( 1 );
				$findIdsMultiSearch->addSearch( $type->createSearch( $query ) );
				$findIdsClosures[] = function( $id ) use
						( $scriptSource, $bulk, $otherIndex, $localSite, &$updatesInBulk ) {
					$script = new \Elastica\Script( $scriptSource, array( 'local_site' => $localSite ) );
					$script->setId( $id );
					$script->setParam( '_type', 'page' );
					$script->setParam( '_index', $otherIndex );
					$bulk->addScript( $script, 'update' );
					$updatesInBulk += 1;
				};
			}
		}
		$findIdsClosuresCount = count( $findIdsClosures );
		if ( $findIdsClosuresCount === 0 ) {
			// No other indexes to check.
			return;
		}

		// Look up the ids and run all closures to build the bulk update
		$start = microtime( true );
		$findIdsMultiSearchResult = $findIdsMultiSearch->search();
		$took = round( ( microtime( true ) - $start ) * 1000 );
		wfDebugLog( 'CirrusSearch', "Searched for $findIdsClosuresCount ids in other indexes in $took millis." );
		for ( $i = 0; $i < $findIdsClosuresCount; $i++ ) {
			$results = $findIdsMultiSearchResult[ $i ]->getResults();
			if ( count( $results ) === 0 ) {
				continue;
			}
			$result = $results[ 0 ];
			$findIdsClosures[ $i ]( $result->getId() );
		}

		if ( $updatesInBulk === 0 ) {
			// None of the titles are in the other index so do nothing.
			return;
		}

		// Execute the bulk update
		$exception = null;
		try {
			$start = microtime( true );
			$bulk->send();
			$took = round( ( microtime( true ) - $start ) * 1000 );
			wfDebugLog( 'CirrusSearch', "Updated $updatesInBulk documents in other indexes in $took millis." );
		} catch ( \Elastica\Exception\Bulk\ResponseException $e ) {
			if ( self::bulkResponseExceptionIsJustDocumentMissing( $e ) ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}
		if ( $exception !== null ) {
			wfLogWarning( 'CirrusSearch other index update failure:  ' . $e->getMessage() );
			foreach ( $titles as $title ) {
				$id = $title->getArticleID();
				wfDebugLog( 'CirrusSearchChangeFailed', "Other Index $actionName: $id" );
			}
		}
	}

	private static function bulkResponseExceptionIsJustDocumentMissing( $exception, $log = false ) {
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
	 * @param $ids array
	 */
	private static function sendDeletes( $ids ) {
		wfProfileIn( __METHOD__ );

		$idCount = count( $ids );
		if ( $idCount === 0 ) {
			return;
		}
		wfDebugLog( 'CirrusSearch', "Sending $idCount deletes to the index." );

		try {
			$start = microtime( true );
			foreach ( Connection::getAllIndexTypes() as $type ) {
				Connection::getPageType( wfWikiId(), $type )
					->deleteIds( $ids );
			}
			$took = round( ( microtime( true ) - $start ) * 1000 );
			wfDebugLog( 'CirrusSearch', "Delete completed in $took millis" );
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			wfLogWarning( "CirrusSearch delete failed caused by:  " . $e->getMessage() );
			foreach ( $ids as $id ) {
				wfDebugLog( 'CirrusSearchChangeFailed', "Delete: $id" );
			}
		}

		wfProfileOut( __METHOD__ );
	}

	/**
	 * Convert an array of pages to an array of their titles.
	 * @param $pages array(WikiPage)
	 * @return array(Title)
	 */
	private static function pagesToTitles( $pages ) {
		$titles = array();
		foreach ( $pages as $page ) {
			$titles[] = $page->getTitle();
		}
		return $titles;
	}
}
