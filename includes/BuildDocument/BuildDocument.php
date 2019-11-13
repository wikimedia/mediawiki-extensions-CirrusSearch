<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use Elastica\Document;
use Hooks;
use IDatabase;
use MediaWiki\Logger\LoggerFactory;
use ParserCache;
use ParserOutput;
use WikiPage;

/**
 * Orchestrate the process of building an elasticsearch document out of a
 * WikiPage. All properties are provided by PagePropertyBuilder instances
 * chosen by a set of provided flags. Operates on batches of pages to
 * facilitate issuing singular requests to external resources, instead of
 * a request for each doc built.
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
	// Bit field parameters for constructor et al.
	const INDEX_EVERYTHING = 0;
	const INDEX_ON_SKIP = 1;
	const SKIP_PARSE = 2;
	const SKIP_LINKS = 4;
	const FORCE_PARSE = 8;
	const INSTANT_INDEX = 16;

	/** @var SearchConfig */
	private $config;
	/** @var Connection */
	private $connection;
	/** @var IDatabase */
	private $db;
	/** @var ParserCache */
	private $parserCache;

	/**
	 * @param Connection $connection Cirrus connection to read page properties from
	 * @param IDatabase $db Wiki database connection to read page properties from
	 * @param ParserCache $parserCache Cache to read parser output from
	 */
	public function __construct( Connection $connection, IDatabase $db, ParserCache $parserCache ) {
		$this->config = $connection->getConfig();
		$this->connection = $connection;
		$this->db = $db;
		$this->parserCache = $parserCache;
	}

	/**
	 * @param \WikiPage[] $pages List of pages to build documents for. These
	 *  pages must represent concrete pages with content. It is expected that
	 *  redirects and non-existent pages have been resolved.
	 * @param int $flags Bitfield of class constants
	 * @return \Elastica\Document[] List of created documents indexed by page id.
	 */
	public function initialize( array $pages, int $flags ): array {
		$documents = [];
		$builders = $this->createBuilders( $flags );
		foreach ( $pages as $page ) {
			if ( !$page->exists() ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Attempted to build a document for a page that doesn\'t exist.  This should be caught ' .
					"earlier but wasn't.  Page: {title}",
					[ 'title' => (string)$page->getTitle() ]
				);
				continue;
			}

			$documents[$page->getId()] = $this->initializeDoc( $page, $builders, $flags );

			// Use of this hook is deprecated, integration should happen through content handler
			// interfaces.
			Hooks::run( 'CirrusSearchBuildDocumentParse', [
				$documents[$page->getId()],
				$page->getTitle(),
				$page->getContent(),
				// Intentionally pass a bogus parser output, restoring this
				// hook is a temporary hack for WikibaseMediaInfo, which does
				// not use the parser output.
				new ParserOutput( null ),
				$this->connection
			] );
		}

		foreach ( $builders as $builder ) {
			$builder->finishInitializeBatch();
		}

		return $documents;
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
		$forceParse = $flags & self::FORCE_PARSE;
		$builders = [ new DefaultPageProperties( $this->db ) ];
		if ( !$skipParse ) {
			$builders[] = new ParserOutputPageProperties( $this->parserCache, $forceParse );
		}
		if ( !$skipLinks ) {
			$builders[] = new RedirectsAndIncomingLinks( $this->connection );
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
	 * @return Document
	 */
	private function initializeDoc( WikiPage $page, array $builders, int $flags ): Document {
		$docId = $this->config->makeId( $page->getId() );
		$doc = new \Elastica\Document( $docId, [] );
		$doc->setDocAsUpsert( $this->canUpsert( $flags ) );

		foreach ( $builders as $builder ) {
			$builder->initialize( $doc, $page );
		}

		return $doc;
	}
}
