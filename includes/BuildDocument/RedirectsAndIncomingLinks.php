<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\SearchConfig;
use CirrusSearch\SearchRequestLog;
use CirrusSearch\Connection;
use CirrusSearch\Search\CirrusIndexField;
use Elastica\Multi\Search as MultiSearch;
use Elastica\Query\BoolQuery;
use Elastica\Query\Terms;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use SplObjectStorage;
use Title;
use WikiPage;

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
	 * @var SplObjectStorage|null copy of this class kept during batches
	 */
	private static $externalLinks = null;

	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var MultiSearch
	 */
	private $linkCountMultiSearch;

	/**
	 * @var callable[]
	 */
	private $linkCountClosures = [];

	/**
	 * @param \Elastica\Document $doc
	 * @param Title $title
	 * @param Connection $conn
	 * @return bool
	 */
	public static function buildDocument( \Elastica\Document $doc, Title $title, Connection $conn ) {
		if ( self::$externalLinks === null ) {
			self::$externalLinks = new SplObjectStorage;
		}
		if ( !isset( self::$externalLinks[$conn] ) ) {
			$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
			// @todo should there instead be a way to source $config from $conn?
			/** @suppress PhanTypeMismatchArgument $config is a SearchConfig instance */
			self::$externalLinks[$conn] = new self( $config, $conn );
		}
		/** @var self $externalLink */
		$externalLink = self::$externalLinks[$conn];
		$externalLink->realBuildDocument( $doc, $title );
		return true;
	}

	/**
	 * @param WikiPage[] $pages
	 * @return bool
	 */
	public static function finishBatch( $pages ) {
		if ( self::$externalLinks === null ) {
			// Nothing to do as we haven't set up any actions during the buildDocument phase
			return false;
		}
		foreach ( self::$externalLinks as $conn ) {
			/** @var self $instance */
			$instance = self::$externalLinks[$conn];
			$instance->realFinishBatch( $pages );
		}
		self::$externalLinks = null;
		return true;
	}

	protected function __construct( SearchConfig $config, Connection $conn ) {
		parent::__construct( $conn, null, 0 );
		$this->config = $config;
		$this->linkCountMultiSearch = new MultiSearch( $conn->getClient() );
	}

	private function realBuildDocument( \Elastica\Document $doc, Title $title ) {
		global $wgCirrusSearchIndexedRedirects;

		$outgoingLinksToCount = [ $title->getPrefixedDBkey() ];

		// Gather redirects to this page
		$redirectTitles = $title->getBacklinkCache()
			->getLinks( 'redirect', false, false, $wgCirrusSearchIndexedRedirects );
		$redirects = [];
		/** @var Title $redirect */
		foreach ( $redirectTitles as $redirect ) {
			// If the redirect is in main OR the same namespace as the article the index it
			if ( $redirect->getNamespace() === NS_MAIN || $redirect->getNamespace() === $title->getNamespace() ) {
				$redirects[] = [
					'namespace' => $redirect->getNamespace(),
					'title' => $redirect->getText()
				];
				$outgoingLinksToCount[] = $redirect->getPrefixedDBkey();
			}
		}
		$doc->set( 'redirect', $redirects );

		// Count links
		// Incoming links is the sum of:
		// #1 Number of redirects to the page
		// #2 Number of links to the title
		// #3 Number of links to all the redirects

		// #1 we have a list of the "first" $wgCirrusSearchIndexedRedirects redirect so we just count it:
		$redirectCount = count( $redirects );

		// #2 and #3 we count the number of links to the page with Elasticsearch.
		// Since we only have $wgCirrusSearchIndexedRedirects we only count that many terms.
		$this->linkCountMultiSearch->addSearch( $this->buildCount( $outgoingLinksToCount ) );
		$this->linkCountClosures[] = function ( $count ) use( $doc, $redirectCount ) {
			$doc->set( 'incoming_links', $count + $redirectCount );
			CirrusIndexField::addNoopHandler( $doc, 'incoming_links', 'within 20%' );
		};
	}

	/**
	 * @param WikiPage[] $pages
	 */
	private function realFinishBatch( array $pages ) {
		$linkCountClosureCount = count( $this->linkCountClosures );
		if ( $linkCountClosureCount ) {
			try {
				$pageCount = count( $pages );
				$this->startNewLog( "counting links to {pageCount} pages", 'count_links', [
					'pageCount' => $pageCount,
					'query' => $pageCount,
				] );
				$result = $this->linkCountMultiSearch->search();
				$this->success();
				for ( $index = 0; $index < $linkCountClosureCount; $index++ ) {
					$this->linkCountClosures[ $index ]( $result[ $index ]->getTotalHits() );
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				// Note that we still return the pages and execute the update here, we just complain
				$this->failure( $e );
				$pageIds = array_map( function ( WikiPage $page ) {
					return $page->getId();
				}, $pages );
				LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->info(
					'Links for page ids: ' . implode( ',', $pageIds ) );
			}
		}
	}

	/**
	 * Build a Search that will count all pages that link to $titles.
	 *
	 * @param string[] $titles title in prefixedDBKey form
	 * @return \Elastica\Search that counts all pages that link to $titles
	 */
	private function buildCount( array $titles ) {
		$bool = new BoolQuery();
		$bool->addFilter( new Terms( 'outgoing_link', $titles ) );

		$indexPrefix = $this->config->get( SearchConfig::INDEX_BASE_NAME );
		$type = $this->connection->getPageType( $indexPrefix );
		$search = new \Elastica\Search( $type->getIndex()->getClient() );
		$search->addIndex( $type->getIndex() );
		$search->addType( $type );
		$search->setQuery( $bool );
		$search->getQuery()->addParam( 'stats', 'link_count' );
		$search->getQuery()->setSize( 0 );

		return $search;
	}

	/**
	 * @param string $description
	 * @param string $queryType
	 * @param array $extra
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
