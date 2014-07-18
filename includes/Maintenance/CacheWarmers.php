<?php

namespace CirrusSearch\Maintenance;
use Elastica;
use \CirrusSearch\Connection;
use \CirrusSearch\Util;
use \CirrusSearch\Search\FullTextResultsType;
use \CirrusSearch\Searcher;
use \Title;

/**
 * Validates cache warmers in an index.
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
class CacheWarmers {
	private $indexType;
	private $pageType;
	private $out;

	public function __construct( $indexType, $pageType, $out ) {
		$this->indexType = $indexType;
		$this->pageType = $pageType;
		$this->out = $out;
	}

	public function validate() {
		$this->out->outputIndented( "Validating cache warmers...\n" );
		$expectedWarmers = $this->buildExpectedWarmers();
		$actualWarmers = $this->fetchActualWarmers();

		$warmersToUpdate = $this->diff( $expectedWarmers, $actualWarmers );
		$warmersToDelete = array_diff_key( $actualWarmers, $expectedWarmers );

		$this->updateWarmers( $warmersToUpdate );
		$this->deleteWarmers( $warmersToDelete );
	}

	private function buildExpectedWarmers() {
		global $wgCirrusSearchMainPageCacheWarmer,
			$wgCirrusSearchCacheWarmers;

		$warmers = array();
		if ( $wgCirrusSearchMainPageCacheWarmer && $this->indexType === 'content' ) {
			$warmers[ 'Main Page' ] = $this->buildWarmer( Title::newMainPage()->getText() );
		}
		if ( isset( $wgCirrusSearchCacheWarmers[ $this->indexType ] ) ) {
			foreach ( $wgCirrusSearchCacheWarmers[ $this->indexType ] as $search ) {
				$warmers[ $search ] = $this->buildWarmer( $search );
			}
		}

		return $warmers;
	}

	private function buildWarmer( $search ) {
		// This has a couple of compromises:
		$searcher = new Searcher(
			0, 50,
			// 0 offset 50 limit is the default for searching so we try it too.
			false,
			// false for namespaces will stop us from eagerly caching the namespace
			// filters. That is probably OK because most searches don't use one.
			// It'd be overeager.
			null
			// Null user because we won't be logging anything about the user.
		);
		$searcher->setReturnQuery( true );
		$searcher->setResultsType( new FullTextResultsType( FullTextResultsType::HIGHLIGHT_ALL ) );
		$searcher->limitSearchToLocalWiki( true );
		$query = $searcher->searchText( $search, true );
		return $query->getValue();
	}

	private function fetchActualWarmers() {
		$data = $this->pageType->getIndex()->request( "_warmer/", 'GET' )->getData();
		$firstKeys = array_keys( $data );
		if ( count( $firstKeys ) === 0 ) {
			return array();
		}
		$warmers = $data[ $firstKeys[ 0 ] ][ 'warmers' ];
		foreach ( $warmers as &$warmer ) {
			// The 'types' field is funky - we can't send it back so we really just pretend it
			// doesn't exist.
			$warmer = $warmer[ 'source' ];
			unset( $warmer[ 'types' ] );
		}
		return $warmers;
	}

	private function updateWarmers( $warmers ) {
		$type = $this->pageType->getName();
		foreach ( $warmers as $name => $contents ) {
			// The types field comes back on warmers but it can't be sent back in
			$this->out->outputIndented( "\tUpdating $name..." );
			$name = urlencode( $name );
			$path = "$type/_warmer/$name";
			$this->pageType->getIndex()->request( $path, 'PUT', $contents );
			$this->out->output( "done\n" );
		}
	}

	private function deleteWarmers( $warmers ) {
		foreach ( $warmers as $name => $contents ) {
			$this->out->outputIndented( "\tDeleting $name..." );
			$name = urlencode( $name );
			$path = "_warmer/$name";
			$this->pageType->getIndex()->request( $path, 'DELETE' );
			$this->out->output( "done\n" );
		}
	}

	private function diff( $expectedWarmers, $actualWarmers ) {
		$result = array();
		foreach ( $expectedWarmers as $key => $value ) {
			if ( !isset( $actualWarmers[ $key ] ) || !Util::recursiveSame( $value, $actualWarmers[ $key ] ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}
}
