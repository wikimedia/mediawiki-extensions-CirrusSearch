<?php

namespace CirrusSearch;
use CirrusSearch\Search\InterwikiResultsType;
use ObjectCache;
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
	 * @var string interwiki prefix
	 */
	private $interwiki;

	/**
	 * Constructor
	 * @param Connection $connection
	 * @param int[] $namespaces Namespace numbers to search
	 * @param User|null $user
	 * @param string $index Base name for index to search from, defaults to wfWikiId()
	 * @param string $interwiki Interwiki prefix we're searching
	 */
	public function __construct( Connection $connection, array $namespaces, User $user = null, $index, $interwiki ) {
		// Only allow core namespaces. We can't be sure any others exist
		if ( $namespaces !== null ) {
			$namespaces = array_filter( $namespaces, function( $namespace ) {
				return $namespace <= 15;
			} );
		}
		parent::__construct( $connection, 0, self::MAX_RESULTS, null, $namespaces, $user, $index );
		$this->interwiki = $interwiki;
	}

	/**
	 * Fetch search results, from caches, if there's any
	 * @param string $term Search term to look for
	 * @return Result
	 */
	public function getInterwikiResults( $term ) {

		// Return early if we can
		if ( !$term ) {
			return null;
		}

		$namespaceKey = $this->getNamespaces() !== null ?
			implode( ',', $this->getNamespaces() ) : '';

		$cache = ObjectCache::getLocalClusterInstance();
		$key = $cache->makeKey(
			'cirrus',
			'interwiki',
			$this->interwiki,
			$namespaceKey,
			md5( $term )
		);
		$ttl = $this->config->get( 'CirrusSearchInterwikiCacheTime' );

		return $cache->getWithSetCallback( $key, $ttl, function () use ( $term ) {
			$this->setResultsType( new InterwikiResultsType( $this->interwiki ) );
			$results = $this->searchText( $term, false );
			if ( $results->isOk() ) {
				return $results->getValue();
			} else {
				return false;
			}
		} );
	}

	/**
	 * Get the index basename for a given interwiki prefix, if one is defined.
	 * @param string $interwiki
	 * @return string
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
	 * We don't support extra indicies when we're doing interwiki searches
	 *
	 * @see Searcher::getAndFilterExtraIndexes()
	 * @return array
	 */
	protected function getAndFilterExtraIndexes() {
		return array();
	}
}
