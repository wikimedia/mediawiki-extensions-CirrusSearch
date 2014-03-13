<?php

namespace CirrusSearch;

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
	 * @var array interwiki mappings to search
	 */
	private $interwikis;

	/**
	 * Constructor
	 * @param array $namespaces Namespace numbers to search
	 * @param string $index Base name for index to search from, defaults to wfWikiId()
	 */
	public function __construct( $namespaces, $user ) {
		global $wgCirrusSearchInterwikiSources;
		parent::__construct( 0, 10, $namespaces, $user );
		$this->interwikis = $wgCirrusSearchInterwikiSources;
		// Only allow core namespaces. We can't be sure any others exist
		if ( $this->namespaces !== null ) {
			$this->namespaces = array_filter( $namespaces, function( $v ) {
				return $v <= 15;
			} );
		}
	}

	/**
	 * Fetch search results, from caches, if there's any
	 * @param string $term Search term to look for
	 * @return ResultSet|null
	 */
	public function getInterwikiResults( $term ) {
		global $wgMemc, $wgCirrusSearchInterwikiCacheTime;

		// Return early if we can
		if ( !$this->interwikis || !$term ) {
			return;
		}

		$key = wfMemcKey(
			'cirrus',
			'interwiki',
			implode( ':', array_keys( $this->interwikis ) ),
			md5( $term )
		);

		$res = $wgMemc->get( $key );
		if ( !$res ) {
			$this->setExplicitIndexes( array_values( $this->interwikis ) );
			$this->setResultsType( new InterwikiResultsType( $this->interwikis ) );
			$results = $this->searchText( $term, false, false );
			if ( $results->isOk() ) {
				$res = $results->getValue();
				$wgMemc->set( $key, $res, $wgCirrusSearchInterwikiCacheTime );
			}
		}

		return $res;
	}
}
