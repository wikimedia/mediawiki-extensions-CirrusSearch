<?php

namespace CirrusSearch\BuildDocument;
use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;

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

		// Handle redirects to this page
		$redirectTitles = $title->getBacklinkCache()
			->getLinks( 'redirect', false, false, $wgCirrusSearchIndexedRedirects );
		$redirects = array();
		$redirectPrefixedDBKeys = array();
		// $redirectLinks = 0;
		foreach ( $redirectTitles as $redirect ) {
			// If the redirect is in main OR the same namespace as the article the index it
			if ( $redirect->getNamespace() === NS_MAIN || $redirect->getNamespace() === $title->getNamespace()) {
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
		$this->linkCountMultiSearch->addSearch( $this->buildCount(
			new \Elastica\Filter\Term( array( 'outgoing_link' => $title->getPrefixedDBKey() ) ) ) );
		$redirectCount = count( $redirects );
		$this->linkCountClosures[] = function ( $count ) use( $doc, $redirectCount ) {
			$doc->add( 'incoming_links', $count + $redirectCount );
		};
		// If a page doesn't have any redirects then count the links to them.
		if ( count( $redirectPrefixedDBKeys ) ) {
			$this->linkCountMultiSearch->addSearch( $this->buildCount(
				new \Elastica\Filter\Terms( 'outgoing_link', $redirectPrefixedDBKeys ) ) );
			$this->linkCountClosures[] = function ( $count ) use( $doc ) {
				$incomingLinks = $doc->has( 'incoming_links' ) ? $doc->get( 'incoming_links' ) : 0;
				$doc->add( 'incoming_links', $count + $incomingLinks );
			};
		}
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
				wfDebugLog( 'CirrusSearchChangeFailed', 'Links for page ids: ' .
					implode( ',', $pageIds ) );
			}
		}
	}

	private function buildCount( $filter ) {
		$type = Connection::getPageType( wfWikiId() );
		$search = new \Elastica\Search( $type->getIndex()->getClient() );
		$search->addIndex( $type->getIndex() );
		$search->addType( $type );
		$search->setOption( \Elastica\Search::OPTION_SEARCH_TYPE,
			\Elastica\Search::OPTION_SEARCH_TYPE_COUNT );
		$matchAll = new \Elastica\Query\MatchAll();
		$search->setQuery( new \Elastica\Query\Filtered( $matchAll, $filter ) );
		$search->getQuery()->addParam( 'stats', 'link_count' );
		return $search;
	}

}
