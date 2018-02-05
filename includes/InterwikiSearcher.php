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
	 * @var int Highlighting bitfield
	 */
	private $highlightingConfig;

	/**
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
		// TODO: possibly move this later and try to detect if we run the default
		// profile, so that we could try to run the default profile on sister wikis
		if ( $namespaces !== null ) {
			$namespaces = array_filter( $namespaces, function ( $namespace ) {
				return $namespace <= NS_CATEGORY_TALK;
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

		$sources = MediaWikiServices::getInstance()
			->getService( InterwikiResolver::SERVICE )
			->getSisterProjectConfigs();
		if ( !$sources ) {
			return null;
		}
		$this->searchContext->setCacheTtl(
			$this->config->get( 'CirrusSearchInterwikiCacheTime' )
		);

		$overriddenProfiles = $this->config->get( 'CirrusSearchCrossProjectProfiles' );
		$contexts = [];
		$resultsType = new FullTextResultsType( $this->highlightingConfig );
		foreach ( $sources as $interwiki => $config ) {
			$overrides = isset( $overriddenProfiles[$interwiki] ) ? $overriddenProfiles[$interwiki] : [];
			$contexts[$interwiki] = $this->buildOverriddenContext( $overrides, $config );
			$contexts[$interwiki]->setResultsType( $resultsType );
		}

		$retval = [];
		$searches = [];
		$this->setResultsType( $resultsType );
		foreach ( $contexts as $interwiki => $context ) {
			$this->searchContext = $context;
			$this->searchContext->setLimitSearchToLocalWiki( true );
			$this->searchContext->setOriginalSearchTerm( $term );
			$this->buildFullTextSearch( $term, false );
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

		if ( $this->isReturnRaw() ) {
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

	/**
	 * Prepare a SearchContext able to run a query on target wiki defined
	 * in $config. The default profile can also be overridden if the default
	 * config is not suited for interwiki searches.
	 *
	 * @param array $overrides List of profiles to override
	 * @param SearchConfig $config the SearchConfig of the target wiki
	 * @return SearchContext a search context ready to run a query on the target wiki
	 */
	private function buildOverriddenContext( array $overrides, SearchConfig $config ) {
		$searchContext = new SearchContext( $config, $this->searchContext->getNamespaces() );
		foreach ( $overrides as $name => $profile ) {
			switch ( $name ) {
			case 'ftbuilder':
				$searchContext->setFulltextQueryBuilderProfile( $profile );
				break;
			case 'rescore':
				$searchContext->setRescoreProfile( $profile );
				break;
			default:
				throw new \RuntimeException( "Cannot override profile: unsupported type $name found in configuration" );
			}
		}
		return $searchContext;
	}
}
