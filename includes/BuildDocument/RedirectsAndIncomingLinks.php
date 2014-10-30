<?php

namespace CirrusSearch\BuildDocument;
use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use Elastica\Filter\Terms;
use Elastica\Search;
use Elastica\Query\Filtered;
use Elastica\Query\MatchAll;

/**
 * Adds redirects and incoming links to the documents.  These are done together
 * because one needs the other.
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
class RedirectsAndIncomingLinks extends ElasticsearchIntermediary {
	/**
	 * @var static copy of this class kept during batches
	 */
	private static $externalLinks = null;

	private $linkCountMultiSearch = null;
	private $linkCountClosures = null;

	public static function buildDocument( $doc, $title ) {
		if ( self::$externalLinks === null ) {
			self::$externalLinks = new self();
		}
		self::$externalLinks->realBuildDocument( $doc, $title );
		return true;
	}

	public static function finishBatch( $pages ) {
		if ( self::$externalLinks === null ) {
			// Nothing to do as we haven't set up any actions during the buildDocument phase
			return;
		}
		self::$externalLinks->realFinishBatch( $pages );
		self::$externalLinks = null;
		return true;
	}

	protected function __construct() {
		parent::__construct( null, null );
		$this->linkCountMultiSearch = new \Elastica\Multi\Search( Connection::getClient() );
		$this->linkCountClosures = array();
	}

	private function realBuildDocument( $doc, $title ) {
		global $wgCirrusSearchIndexedRedirects;

		$outgoingLinksToCount = array( $title->getPrefixedDBKey() );

		// Gather redirects to this page
		$redirectTitles = $title->getBacklinkCache()
			->getLinks( 'redirect', false, false, $wgCirrusSearchIndexedRedirects );
		$redirects = array();
		foreach ( $redirectTitles as $redirect ) {
			// If the redirect is in main OR the same namespace as the article the index it
			if ( $redirect->getNamespace() === NS_MAIN || $redirect->getNamespace() === $title->getNamespace()) {
				$redirects[] = array(
					'namespace' => $redirect->getNamespace(),
					'title' => $redirect->getText()
				);
				$outgoingLinksToCount[] = $redirect->getPrefixedDBKey();
			}
		}
		$doc->add( 'redirect', $redirects );

		// Count links
		// Incoming links is the sum of:
		//  #1 Number of redirects to the page
		//  #2 Number of links to the title
		//  #3 Number of links to all the redirects

		// #1 we have a list of the "first" $wgCirrusSearchIndexedRedirects redirect so we just count it:
		$redirectCount = count( $redirects );

		// #2 and #3 we count the number of links to the page with Elasticsearch.
		// Since we only have $wgCirrusSearchIndexedRedirects we only count that many terms.
		$this->linkCountMultiSearch->addSearch( $this->buildCount( $outgoingLinksToCount ) );
		$this->linkCountClosures[] = function ( $count ) use( $doc, $redirectCount ) {
			$doc->add( 'incoming_links', $count + $redirectCount );
		};
	}

	private function realFinishBatch( $pages ) {
		$linkCountClosureCount = count( $this->linkCountClosures );
		if ( $linkCountClosureCount ) {
			try {
				$pageCount = count( $pages );
				$this->start( "counting links to $pageCount pages" );
				$result = $this->linkCountMultiSearch->search();
				$this->success();
				for ( $index = 0; $index < $linkCountClosureCount; $index++ ) {
					$this->linkCountClosures[ $index ]( $result[ $index ]->getTotalHits() );
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				// Note that we still return the pages and execute the update here, we just complain
				$this->failure( $e );
				$pageIds = array_map( function( $page ) {
					return $page->getId();
				}, $pages );
				wfDebugLog( 'CirrusSearchChangeFailed', 'Links for page ids: ' . implode( ',', $pageIds ) );
			}
		}
	}

	/**
	 * Build a Search that will count all pages that link to $titles.
	 * @param string $titles title in prefixedDBKey form
	 * @return Search that counts all pages that link to $titles
	 */
	private function buildCount( $titles ) {
		$filter = new Terms( 'outgoing_link', $titles );
		$filter->setCached( false ); // We're not going to be redoing this any time soon.
		$type = Connection::getPageType( wfWikiId() );
		$search = new Search( $type->getIndex()->getClient() );
		$search->addIndex( $type->getIndex() );
		$search->addType( $type );
		$search->setOption( Search::OPTION_SEARCH_TYPE, Search::OPTION_SEARCH_TYPE_COUNT );
		$matchAll = new MatchAll();
		$search->setQuery( new Filtered( $matchAll, $filter ) );
		$search->getQuery()->addParam( 'stats', 'link_count' );
		return $search;
	}
}
