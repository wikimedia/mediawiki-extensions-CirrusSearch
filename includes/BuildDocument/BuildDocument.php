<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Connection;
use CirrusSearch\Search\CirrusIndexField;
use CirrusSearch\SearchConfig;
use Elastica\Document;
use MediaWiki\Cache\BacklinkCacheFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionAccessException;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Rdbms\IReadableDatabase;
use WikiPage;

/**
 * Orchestrate the process of building an elasticsearch document out of a
 * WikiPage. Document building is performed in two stages, and all properties
 * are provided by PagePropertyBuilder instances chosen by a set of provided
 * flags.
 *
 * The first stage, called initialize, sets up the basic document properties.
 * This stage is executed one time per update and the results are shared
 * between all retry attempts and clusters to be written to. The results of the
 * initialize stage may be written to the job queue, so we try to keep the size
 * of these documents reasonable small. The initialize stage supports batching
 * initialization by the PagePropertyBuilder instances.
 *
 * The second stage of document building, finalize, is called on each attempt
 * to send a document to an elasticsearch cluster. This stage loads the bulk
 * content, potentially megabytes, from mediawiki ParserOutput into the
 * documents.
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
class BuildDocument {
	private const HINT_FLAGS = 'BuildDocument_flags';

	// Bit field parameters for constructor et al.
	public const INDEX_EVERYTHING = 0;
	public const INDEX_ON_SKIP = 1;
	public const SKIP_PARSE = 2;
	public const SKIP_LINKS = 4;

	/** @var SearchConfig */
	private $config;
	/** @var Connection */
	private $connection;
	/** @var IReadableDatabase */
	private $db;
	/** @var RevisionStore */
	private $revStore;
	/** @var BacklinkCacheFactory */
	private $backlinkCacheFactory;
	/** @var DocumentSizeLimiter */
	private $documentSizeLimiter;
	/** @var TitleFormatter */
	private $titleFormatter;
	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/**
	 * @param Connection $connection Cirrus connection to read page properties from
	 * @param IReadableDatabase $db Wiki database connection to read page properties from
	 * @param RevisionStore $revStore Store for retrieving revisions by id
	 * @param BacklinkCacheFactory $backlinkCacheFactory
	 * @param DocumentSizeLimiter $docSizeLimiter
	 * @param TitleFormatter $titleFormatter
	 * @param WikiPageFactory $wikiPageFactory
	 */
	public function __construct(
		Connection $connection,
		IReadableDatabase $db,
		RevisionStore $revStore,
		BacklinkCacheFactory $backlinkCacheFactory,
		DocumentSizeLimiter $docSizeLimiter,
		TitleFormatter $titleFormatter,
		WikiPageFactory $wikiPageFactory
	) {
		$this->config = $connection->getConfig();
		$this->connection = $connection;
		$this->db = $db;
		$this->revStore = $revStore;
		$this->backlinkCacheFactory = $backlinkCacheFactory;
		$this->documentSizeLimiter = $docSizeLimiter;
		$this->titleFormatter = $titleFormatter;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * @param \WikiPage[]|RevisionRecord[] $pagesOrRevs List of pages to build documents for. These
	 *  pages must represent concrete pages with content. It is expected that
	 *  redirects and non-existent pages have been resolved.
	 * @param int $flags Bitfield of class constants
	 * @return \Elastica\Document[] List of created documents indexed by page id.
	 */
	public function initialize( array $pagesOrRevs, int $flags ): array {
		$documents = [];
		$builders = $this->createBuilders( $flags );
		foreach ( $pagesOrRevs as $pageOrRev ) {
			if ( $pageOrRev instanceof RevisionRecord ) {
				$revision = $pageOrRev;
				$page = $this->wikiPageFactory->newFromTitle( $revision->getPage() );
			} else {
				$revision = $pageOrRev->getRevisionRecord();
				$page = $pageOrRev;
			}
			if ( !$page->exists() ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Attempted to build a document for a page that doesn\'t exist.  This should be caught ' .
					"earlier but wasn't.  Page: {title}",
					[ 'title' => (string)$page->getTitle() ]
				);
				continue;
			}

			if ( $revision == null ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Attempted to build a document for a page that doesn\'t have a revision. This should be caught ' .
					"earlier but wasn't.  Page: {title}",
					[ 'title' => (string)$page->getTitle() ]
				);
				continue;
			}

			$documents[$page->getId()] = $this->initializeDoc( $page, $builders, $flags, $revision );
		}

		foreach ( $builders as $builder ) {
			$builder->finishInitializeBatch();
		}

		return $documents;
	}

	/**
	 * Finalize building a page document.
	 *
	 * Called on every attempt to write the document to elasticsearch, meaning
	 * every cluster and every retry. Any bulk data that needs to be loaded
	 * should happen here.
	 *
	 * @param Document $doc
	 * @param bool $enforceLatest
	 * @param RevisionRecord|null $revision
	 * @return bool True when the document update can proceed
	 * @throws BuildDocumentException
	 */
	public function finalize( Document $doc, bool $enforceLatest = true, ?RevisionRecord $revision = null ): bool {
		$flags = CirrusIndexField::getHint( $doc, self::HINT_FLAGS );
		if ( $flags !== null ) {
			$docRevision = $doc->get( 'version' );
			if ( $revision !== null && $docRevision !== $revision->getId() ) {
				throw new \RuntimeException( "Revision id mismatch: {$revision->getId()} != $docRevision" );
			}
			try {
				$revision ??= $this->revStore->getRevisionById( $docRevision );
				$title = $revision ? Title::castFromPageIdentity( $revision->getPage() ) : null;
			} catch ( RevisionAccessException $e ) {
				$revision = null;
			}
			if ( !$title || !$revision ) {
				LoggerFactory::getInstance( 'CirrusSearch' )
					->warning( 'Ignoring a page/revision that no longer exists {rev_id}',
						[ 'rev_id' => $docRevision ] );

				return false;
			}
			if ( $enforceLatest && $title->getLatestRevID() !== $docRevision ) {
				// Something has changed since the job was enqueued, this is no longer
				// a valid update.
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Skipping a page/revision update for revision {rev} because a new one is available',
					[ 'rev' => $docRevision ] );
				return false;
			}
			$builders = $this->createBuilders( $flags );
			foreach ( $builders as $builder ) {
				$builder->finalize( $doc, $title, $revision );
			}
			$this->documentSizeLimiter->resize( $doc );
		}
		return true;
	}

	/**
	 * Construct PagePropertyBuilder instances suitable for provided flags
	 *
	 * Visible for testing. Should be private.
	 *
	 * @param int $flags Bitfield of class constants
	 * @return PagePropertyBuilder[]
	 */
	protected function createBuilders( int $flags ): array {
		$skipLinks = $flags & self::SKIP_LINKS;
		$skipParse = $flags & self::SKIP_PARSE;
		$builders = [ new DefaultPageProperties( $this->db ) ];
		if ( !$skipParse ) {
			$builders[] = new ParserOutputPageProperties( $this->config );
		}
		if ( !$skipLinks ) {
			$builders[] = new RedirectsAndIncomingLinks(
				$this->connection,
				$this->backlinkCacheFactory,
				$this->titleFormatter
			);
		}
		return $builders;
	}

	/**
	 * Everything is sent as an update to prevent overwriting fields maintained in other processes
	 * like OtherIndex::updateOtherIndex.
	 *
	 * But we need a way to index documents that don't already exist.  We're willing to upsert any
	 * full documents or any documents that we've been explicitly told it is ok to index when they
	 * aren't full. This is typically just done during the first phase of the initial index build.
	 * A quick note about docAsUpsert's merging behavior:  It overwrites all fields provided by doc
	 * unless they are objects in both doc and the indexed source.  We're ok with this because all of
	 * our fields are either regular types or lists of objects and lists are overwritten.
	 *
	 * @param int $flags Bitfield of class constants
	 * @return bool True when upsert is allowed with the provided flags
	 */
	private function canUpsert( int $flags ): bool {
		$skipParse = $flags & self::SKIP_PARSE;
		$skipLinks = $flags & self::SKIP_LINKS;
		$indexOnSkip = $flags & self::INDEX_ON_SKIP;
		$fullDocument = !( $skipParse || $skipLinks );
		return $fullDocument || $indexOnSkip;
	}

	/**
	 * Perform initial building of a page document. This is called
	 * once when starting an update and is shared between all clusters
	 * written to. This doc may be written to the jobqueue multiple
	 * times and should not contain any large values.
	 *
	 * @param WikiPage $page
	 * @param PagePropertyBuilder[] $builders
	 * @param int $flags
	 * @param RevisionRecord $revision
	 * @return Document
	 */
	private function initializeDoc( WikiPage $page, array $builders, int $flags, RevisionRecord $revision ): Document {
		$docId = $this->config->makeId( $page->getId() );
		$doc = new \Elastica\Document( $docId, [] );
		// allow self::finalize to recreate the same set of builders
		CirrusIndexField::setHint( $doc, self::HINT_FLAGS, $flags );
		$doc->setDocAsUpsert( $this->canUpsert( $flags ) );
		$doc->set( 'version', $revision->getId() );
		CirrusIndexField::addNoopHandler(
			$doc, 'version', 'documentVersion' );

		foreach ( $builders as $builder ) {
			$builder->initialize( $doc, $page, $revision );
		}

		return $doc;
	}
}
