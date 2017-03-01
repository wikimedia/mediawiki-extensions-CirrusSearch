<?php

namespace CirrusSearch;

use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\ResultSet;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use SpecialPageFactory;
use User;

/**
 * Performs searches using Elasticsearch -- on interwikis!
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
class InterwikiSearcher extends Searcher {
	/**
	 * @var int Max complexity allowed to run on other indices
	 */
	const MAX_COMPLEXITY = 10;

	/**
	 * @var int Highlighting bitfield
	 */
	private $highlightingConfig;

	/**
	 * Constructor
	 * @param Connection $connection
	 * @param SearchConfig $config
	 * @param int[]|null $namespaces Namespace numbers to search, or null for all of them
	 * @param User|null $user
	 * @param int $highlightingConfig Bitmask of FullTextResultsType::HIGHLIGHT_… constants
	 */
	public function __construct(
		Connection $connection,
		SearchConfig $config,
		array $namespaces = null,
		User $user = null,
		$highlightingConfig = FullTextResultsType::HIGHLIGHT_NONE
	) {
		// Only allow core namespaces. We can't be sure any others exist
		if ( $namespaces !== null ) {
			$namespaces = array_filter( $namespaces, function( $namespace ) {
				return $namespace <= 15;
			} );
		}
		$maxResults = $config->get( 'CirrusSearchNumCrossProjectSearchResults' );
		parent::__construct( $connection, 0, $maxResults, $config, $namespaces, $user );
		$this->highlightingConfig = $highlightingConfig;
	}

	/**
	 * Fetch search results, from caches, if there's any
	 * @param string $term Search term to look for
	 * @return ResultSet[]|null
	 */
	public function getInterwikiResults( $term ) {
		// Return early if we can
		if ( !$term ) {
			return null;
		}

		if ( !$this->config->isCrossProjectSearchEnabled() ) {
			// TODO: we should probably call this before (in the
			// CirrusSearch class) to avoid creating an object for
			// nothing.
			return null;
		}

		$sources = MediaWikiServices::getInstance()
			->getService( InterwikiResolver::SERVICE )
			->getSisterProjectPrefixes();
		if ( !$sources ) {
			return null;
		}
		$this->searchContext->setCacheTtl(
			$this->config->get( 'CirrusSearchInterwikiCacheTime' )
		);

		$this->searchContext->setLimitSearchToLocalWiki( true );
		$this->searchContext->setOriginalSearchTerm( $term );
		$this->buildFullTextSearch( $term, false );
		$context = $this->searchContext;

		// Avoid costly queries to run on other indices
		if ( $this->searchContext->getSearchComplexity() > self::MAX_COMPLEXITY ) {
			return null;
		}

		$retval = [];
		$searches = [];
		$resultsTypes = [];
		foreach ( $sources as $interwiki => $index ) {
			// TODO: remove when getWikiCode is removed.
			// In theory we should be able to reuse the same
			// Results type for all searches
			$resultsTypes[$interwiki] = new FullTextResultsType( $this->config->newInterwikiConfig( $index, false ), $this->highlightingConfig );
			$this->setResultsType( $resultsTypes[$interwiki] );
			$this->indexBaseName = $index;
			$this->searchContext = clone $context;
			$search = $this->buildSearch();
			if ( $this->searchContext->areResultsPossible() ) {
				$searches[$interwiki] = $search;
			} else {
				$retval[$interwiki] = [];
			}
		}

		$results = $this->searchMulti( $searches, $resultsTypes );
		if ( !$results->isOK() ) {
			return null;
		}

		$retval = array_merge( $retval, $results->getValue() );

		if ( $this->isReturnRaw() ) {
			return $retval;
		}

		switch ( $this->config->get( 'CirrusSearchCrossProjectOrder' ) ) {
		case 'recall':
			uasort( $retval, function( $a, $b ) {
				return $b->getTotalHits() - $a->getTotalHits();
			} );
			return $retval;
		case 'random':
			// reset the random number generator
			// take the first 8 chars from the md5 to build a uint32
			// and to prevent hexdec from returning floats
			mt_srand( hexdec( substr( Util::generateIdentToken(), 0, 8 ) ) );
			$sortKeys = array_map( function () { return mt_rand(); }, $retval );
			// "Randomly" sort crossproject results
			// Should give the same order for the same identity
			array_multisort( $sortKeys, SORT_ASC, $retval );
			return $retval;
		case 'static':
			return $retval;
		default:
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				'wgCirrusSearchCrossProjectOrder is set to ' .
				'unkown value {invalid_order} using static ' .
				'instead.',
				[ 'invalid_order' => $this->config->get( 'CirrusSearchCrossProjectOrder' ) ]
			);
			return $retval;
		}
	}

	/**
	 * We don't support extra indices when we're doing interwiki searches
	 *
	 * @see Searcher::getAndFilterExtraIndexes()
	 * @return array
	 */
	protected function getAndFilterExtraIndexes() {
		return [];
	}

	/**
	 * @return string The stats key used for reporting hit/miss rates of the
	 *  application side query cache.
	 */
	protected function getQueryCacheStatsKey() {
		return 'CirrusSearch.query_cache.interwiki';
	}
}
