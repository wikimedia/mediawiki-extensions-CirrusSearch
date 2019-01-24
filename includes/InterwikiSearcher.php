<?php

namespace CirrusSearch;

use CirrusSearch\Fallbacks\FallbackRunner;
use CirrusSearch\Search\CrossProjectBlockScorerFactory;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;
use MediaWiki\MediaWikiServices;
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
	 * @param Connection $connection
	 * @param SearchConfig $config
	 * @param int[]|null $namespaces Namespace numbers to search, or null for all of them
	 * @param User|null $user
	 * @param CirrusDebugOptions|null $debugOptions
	 */
	public function __construct(
		Connection $connection,
		SearchConfig $config,
		array $namespaces = null,
		User $user = null,
		CirrusDebugOptions $debugOptions = null
	) {
		$maxResults = $config->get( 'CirrusSearchNumCrossProjectSearchResults' );
		parent::__construct( $connection, 0, $maxResults, $config, $namespaces, $user, false, $debugOptions );
	}

	/**
	 * Fetch search results, from caches, if there's any
	 * @param SearchQuery $query original search query
	 * @return ResultSet[]|null
	 */
	public function getInterwikiResults( SearchQuery $query ) {
		$sources = MediaWikiServices::getInstance()
			->getService( InterwikiResolver::SERVICE )
			->getSisterProjectConfigs();
		if ( !$sources ) {
			return null;
		}

		$iwQueries = [];
		$resultsType = new FullTextResultsType();
		foreach ( $sources as $interwiki => $config ) {
			$iwQueries[$interwiki] = SearchQueryBuilder::forCrossProjectSearch( $config, $query )
				->build();
		}

		$retval = [];
		$searches = [];
		$this->setResultsType( $resultsType );
		$blockScorer = CrossProjectBlockScorerFactory::load( $this->config );
		foreach ( $iwQueries as $interwiki => $iwQuery ) {
			$context = SearchContext::fromSearchQuery( $iwQuery,
				FallbackRunner::create( $this, $iwQuery ) );
			$this->searchContext = $context;
			$this->config = $context->getConfig();
			$this->limit = $iwQuery->getLimit();
			$this->offset = $iwQuery->getOffset();
			$this->buildFullTextSearch( $query->getParsedQuery()->getQueryWithoutNsHeader() );
			$this->indexBaseName = $context->getConfig()->get( 'CirrusSearchIndexBaseName' );
			$search = $this->buildSearch();
			if ( $this->searchContext->areResultsPossible() ) {
				$searches[$interwiki] = $search;
			} else {
				$retval[$interwiki] = [];
			}
		}

		$results = $this->searchMulti( $searches );
		if ( !$results->isOK() ) {
			return null;
		}

		$retval = array_merge( $retval, $results->getValue() );

		if ( $this->searchContext->getDebugOptions()->isReturnRaw() ) {
			return $retval;
		}

		return $blockScorer->reorder( $retval );
	}

	/**
	 * @return string The stats key used for reporting hit/miss rates of the
	 *  application side query cache.
	 */
	protected function getQueryCacheStatsKey() {
		return 'CirrusSearch.query_cache.interwiki';
	}
}
