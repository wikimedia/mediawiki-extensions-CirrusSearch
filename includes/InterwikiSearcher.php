<?php

namespace CirrusSearch;

use CirrusSearch\Search\InterwikiResultsType;
use CirrusSearch\Search\ResultSet;
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
	 * @var int Max number of results to fetch from other wiki
	 */
	const MAX_RESULTS = 5;

	/**
	 * @var bool Is the interwiki load test configured?
	 */
	private $isLoadTest = false;

	/**
	 * @var bool Is the interwiki load test enabled?
	 */
	private $isLoadTestEnabled = false;

	/**
	 * Constructor
	 * @param Connection $connection
	 * @param SearchConfig $config
	 * @param int[]|null $namespaces Namespace numbers to search, or null for all of them
	 * @param User|null $user
	 * @param string $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 */
	public function __construct( Connection $connection, SearchConfig $config, array $namespaces = null, User $user = null ) {
		// Only allow core namespaces. We can't be sure any others exist
		if ( $namespaces !== null ) {
			$namespaces = array_filter( $namespaces, function( $namespace ) {
				return $namespace <= 15;
			} );
		}

		$limit = self::MAX_RESULTS;

		$loadTestPercent = $config->get( 'CirrusSearchInterwikiLoadTest' );
		if ($loadTestPercent !== null ) {
			$this->isLoadTest = true;

			$requestedTitle = \RequestContext::getMain()->getTitle();

			$isSpecialSearch = $requestedTitle &&
				$requestedTitle->getNamespace() === NS_SPECIAL &&
				SpecialPageFactory::resolveAlias( $requestedTitle->getText() )[0] === 'Search';
			$rand = mt_rand( 1, PHP_INT_MAX ) / PHP_INT_MAX;
			if ( $isSpecialSearch && $rand <= $loadTestPercent ) {
				$this->isLoadTestEnabled = true;
				ElasticsearchIntermediary::appendPayload( 'interwikiLoadTest', 'true' );
				$limit = 1;
			}
		}

		parent::__construct( $connection, 0, $limit, $config, $namespaces, $user );
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

		$sources = $this->config->get( 'CirrusSearchInterwikiSources' );
		if ( !$sources ) {
			return null;
		}

		if ( $this->isLoadTest && !$this->isLoadTestEnabled ) {
			return null;
		}

		$this->searchContext->setCacheTtl(
			$this->config->get( 'CirrusSearchInterwikiCacheTime' )
		);

		$this->searchContext->setLimitSearchToLocalWiki( true );
		$this->searchContext->setOriginalSearchTerm( $term );
		$this->buildFullTextSearch( $term, false );
		$context = $this->searchContext;

		if ( $context->isSyntaxUsed() &&
			$this->searchContext->getSyntaxUsed() !== ['query_string']
		) {
			return null;
		}

		$retval = [];
		$searches = [];
		$resultsTypes = [];
		foreach ( $sources as $interwiki => $index ) {
			// Note that this is a hack, $this->resultsType is not properly
			// specialized to the interwiki use case, but because we are not
			// returning load test results to the users that is acceptable.
			if (!$this->isLoadTestEnabled ) {
				$resultsTypes[$interwiki] = new InterwikiResultsType( $interwiki );
				$this->setResultsType( $resultsTypes[$interwiki] );
			}
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

		if ($this->isLoadTest) {
			// For the load test we are generating the results, but not
			// returning them to the user.
			return null;
		} else {
			return array_merge( $retval, $results->getValue() );
		}
	}

	/**
	 * Get the index basename for a given interwiki prefix, if one is defined.
	 * @param string $interwiki
	 * @return string|null
	 */
	public static function getIndexForInterwiki( $interwiki ) {
		// These settings should be common for all wikis, so globals
		// are _probably_ OK here.
		global $wgCirrusSearchInterwikiSources, $wgCirrusSearchWikiToNameMap;

		if ( isset( $wgCirrusSearchInterwikiSources[$interwiki] ) ) {
			return $wgCirrusSearchInterwikiSources[$interwiki];
		}

		if ( isset( $wgCirrusSearchWikiToNameMap[$interwiki] ) ) {
			return $wgCirrusSearchWikiToNameMap[$interwiki];
		}

		return null;
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
