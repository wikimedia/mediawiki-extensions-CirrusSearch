<?php

namespace CirrusSearch;
use CirrusSearch\Search\InterwikiResultsType;

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
	 * @param array $namespaces Namespace numbers to search
	 * @param array $namespaces Namespace numbers to search
	 * @param string $index Base name for index to search from, defaults to wfWikiId()
	 * @param string $interwiki Interwiki prefix we're searching
	 */
	public function __construct( $namespaces, $user, $index, $interwiki ) {
		parent::__construct( 0, self::MAX_RESULTS, $namespaces, $user, $index );
		$this->interwiki = $interwiki;
		// Only allow core namespaces. We can't be sure any others exist
		if ( $this->namespaces !== null ) {
			$this->namespaces = array_filter( $namespaces, function( $namespace ) {
				return $namespace <= 15;
			} );
		}
	}

	/**
	 * Fetch search results, from caches, if there's any
	 * @param string $term Search term to look for
	 * @return Result
	 */
	public function getInterwikiResults( $term ) {
		global $wgMemc, $wgCirrusSearchInterwikiCacheTime;

		// Return early if we can
		if ( !$term ) {
			return;
		}

		$namespaceKey = $this->namespaces !== null ?
			implode( ',', $this->namespaces ) : '';

		$results = array();
		$key = wfMemcKey(
			'cirrus',
			'interwiki',
			$this->interwiki,
			$namespaceKey,
			md5( $term )
		);

		$res = $wgMemc->get( $key );
		if ( !$res ) {
			$this->setResultsType( new InterwikiResultsType( $this->interwiki ) );
			$results = $this->searchText( $term, false );
			if ( $results->isOk() ) {
				$res = $results->getValue();
				$wgMemc->set( $key, $res, $wgCirrusSearchInterwikiCacheTime );
			}
		}
		return $res;
	}

	/**
	 * Get the index basename for a given interwiki prefix, if one is defined.
	 * @return string
	 */
	public static function getIndexForInterwiki( $interwiki ) {
		global $wgCirrusSearchInterwikiSources;
		return isset( $wgCirrusSearchInterwikiSources[ $interwiki ] ) ?
			$wgCirrusSearchInterwikiSources[ $interwiki ] : null;
	}

	/**
	 * We don't support extra indicies when we're doing interwiki searches
	 *
	 * @see Searcher::getAndFilterExtraIndexes()
	 * @return array()
	 */
	protected function getAndFilterExtraIndexes() {
		return array();
	}
}
