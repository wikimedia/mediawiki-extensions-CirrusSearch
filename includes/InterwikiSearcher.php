<?php

namespace CirrusSearch;

use CirrusSearch\Search\CrossProjectBlockScorerFactory;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchContext;
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
	 * @var int Max complexity allowed to run on other indices
	 */
	const MAX_COMPLEXITY = 10;

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
		// Only allow core namespaces. We can't be sure any others exist
		// TODO: possibly move this later and try to detect if we run the default
		// profile, so that we could try to run the default profile on sister wikis
		if ( $namespaces !== null ) {
			$namespaces = array_filter( $namespaces, function ( $namespace ) {
				return $namespace <= NS_CATEGORY_TALK;
			} );
		}
		$maxResults = $config->get( 'CirrusSearchNumCrossProjectSearchResults' );
		parent::__construct( $connection, 0, $maxResults, $config, $namespaces, $user, false, $debugOptions );
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

		$sources = MediaWikiServices::getInstance()
			->getService( InterwikiResolver::SERVICE )
			->getSisterProjectConfigs();
		if ( !$sources ) {
			return null;
		}

		$contexts = [];
		$resultsType = new FullTextResultsType();
		foreach ( $sources as $interwiki => $config ) {
			$contexts[$interwiki] = new SearchContext( $config, $this->searchContext->getNamespaces(), $this->searchContext->getDebugOptions() );
			$contexts[$interwiki]->setResultsType( $resultsType );
		}

		$retval = [];
		$searches = [];
		$this->setResultsType( $resultsType );
		foreach ( $contexts as $interwiki => $context ) {
			$this->searchContext = $context;
			$this->searchContext->setLimitSearchToLocalWiki( true );
			$this->searchContext->setOriginalSearchTerm( $term );
			$this->searchContext->setSuggestion( false );
			$this->buildFullTextSearch( $term );
			$this->searchContext = $context;
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

		return CrossProjectBlockScorerFactory::load( $this->config )->reorder( $retval );
	}

	/**
	 * @return string The stats key used for reporting hit/miss rates of the
	 *  application side query cache.
	 */
	protected function getQueryCacheStatsKey() {
		return 'CirrusSearch.query_cache.interwiki';
	}
}
