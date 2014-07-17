<?php

namespace CirrusSearch;
use \Title;

/**
 * Tracks whether a Title is known on other indexes.
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
class OtherIndexes extends Updater {
	/** @var string Local site we're tracking */
	private $localSite;

	/**
	 * Constructor
	 * @param string $localSite
	 */
	public function __construct( $localSite ) {
		parent::__construct();
		$this->localSite = $localSite;
	}

	/**
	 * Get the external index identifiers for title.
	 * @param $title Title
	 * @return array(string) of index identifiers.  empty means none.
	 */
	public static function getExternalIndexes( Title $title ) {
		global $wgCirrusSearchExtraIndexes;
		$namespace = $title->getNamespace();
		return isset( $wgCirrusSearchExtraIndexes[ $namespace ] )
			? $wgCirrusSearchExtraIndexes[ $namespace ] : array();
	}

	/**
	 * Get any extra indexes to query, if any, based on namespaces
	 * @param array $namespaces An array of namespace ids
	 * @return array of indexes
	 */
	public static function getExtraIndexesForNamespaces( $namespaces ) {
		global $wgCirrusSearchExtraIndexes;
		$extraIndexes = array();
		if ( $wgCirrusSearchExtraIndexes ) {
			foreach( $wgCirrusSearchExtraIndexes as $namespace => $indexes ) {
				if ( in_array( $namespace, $namespaces ) ) {
					$extraIndexes = array_merge( $extraIndexes, $indexes );
				}
			}
		}
		return $extraIndexes;
	}

		/**
	 * Add the local wiki to the duplicate tracking list on the indexes of other wikis for $titles.
	 * @param Array(Title) $titles titles for which to add to the tracking list
	 */
	public function addLocalSiteToOtherIndex( $titles ) {
		// Script is in MVEL and is run in a context with local_site set to this wiki's name
		$script  = <<<MVEL
			if (!ctx._source.containsKey("local_sites_with_dupe")) {
				ctx._source.local_sites_with_dupe = [local_site]
			} else if (ctx._source.local_sites_with_dupe.contains(local_site)) {
				ctx.op = "none"
			} else {
				ctx._source.local_sites_with_dupe += local_site
			}
MVEL;
		$this->updateOtherIndex( 'addLocalSite', $script, $titles );
	}

	/**
	 * Remove the local wiki from the duplicate tracking list on the indexes of other wikis for $titles.
	 * @param array(Title) $titles titles for which to remove the tracking field
	 */
	public function removeLocalSiteFromOtherIndex( $titles ) {
		// Script is in MVEL and is run in a context with local_site set to this wiki's name
		$script  = <<<MVEL
			if (!ctx._source.containsKey("local_sites_with_dupe")) {
				ctx.op = "none"
			} else if (!ctx._source.local_sites_with_dupe.remove(local_site)) {
				ctx.op = "none"
			}
MVEL;
		$this->updateOtherIndex( 'removeLocalSite', $script, $titles );
	}

	/**
	 * Update the indexes for other wiki that also store information about $titles.
	 * @param string $actionName name of the action to report in logging
	 * @param string $scriptSource MVEL source script for performing the update
	 * @param array(Title) $titles titles in other indexes to update
	 * @return bool false on failure, null otherwise
	 */
	private function updateOtherIndex( $actionName, $scriptSource, $titles ) {
		$client = Connection::getClient();
		$bulk = new \Elastica\Bulk( $client );
		$updatesInBulk = 0;

		// Build multisearch to find ids to update
		$findIdsMultiSearch = new \Elastica\Multi\Search( Connection::getClient() );
		$findIdsClosures = array();
		$localSite = $this->localSite;
		foreach ( $titles as $title ) {
			foreach ( OtherIndexes::getExternalIndexes( $title ) as $otherIndex ) {
				if ( $otherIndex === null ) {
					continue;
				}
				$type = Connection::getPageType( $otherIndex );
				$bool = new \Elastica\Filter\Bool();
				// Note that we need to use the keyword indexing of title so the analyzer gets out of the way.
				$bool->addMust( new \Elastica\Filter\Term( array( 'title.keyword' => $title->getText() ) ) );
				$bool->addMust( new \Elastica\Filter\Term( array( 'namespace' => $title->getNamespace() ) ) );
				$filtered = new \Elastica\Query\Filtered( new \Elastica\Query\MatchAll(), $bool );
				$query = new \Elastica\Query( $filtered );
				$query->setFields( array() ); // We only need the _id so don't load the _source
				$query->setSize( 1 );
				$findIdsMultiSearch->addSearch( $type->createSearch( $query ) );
				$findIdsClosures[] = function( $id ) use
						( $scriptSource, $bulk, $otherIndex, $localSite, &$updatesInBulk ) {
					$script = new \Elastica\Script( $scriptSource, array( 'local_site' => $localSite ), 'mvel' );
					$script->setId( $id );
					$script->setParam( '_type', 'page' );
					$script->setParam( '_index', $otherIndex );
					$bulk->addScript( $script, 'update' );
					$updatesInBulk += 1;
				};
			}
		}
		$findIdsClosuresCount = count( $findIdsClosures );
		if ( $findIdsClosuresCount === 0 ) {
			// No other indexes to check.
			return;
		}

		// Look up the ids and run all closures to build the bulk update
		$this->start( "searching for $findIdsClosuresCount ids in other indexes" );
		$findIdsMultiSearchResult = $findIdsMultiSearch->search();
		try {
			$this->success();
			for ( $i = 0; $i < $findIdsClosuresCount; $i++ ) {
				$results = $findIdsMultiSearchResult[ $i ]->getResults();
				if ( count( $results ) === 0 ) {
					continue;
				}
				$result = $results[ 0 ];
				$findIdsClosures[ $i ]( $result->getId() );
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$this->failure( $e );
			return;
		}

		if ( $updatesInBulk === 0 ) {
			// None of the titles are in the other index so do nothing.
			return;
		}

		// Execute the bulk update
		$exception = null;
		try {
			$this->start( "updating $updatesInBulk documents in other indexes" );
			$bulk->send();
		} catch ( \Elastica\Exception\Bulk\ResponseException $e ) {
			if ( $this->bulkResponseExceptionIsJustDocumentMissing( $e, null ) ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}
		if ( $exception === null ) {
			$this->success();
		} else {
			$this->failure( $e );
			$articleIDs = array_map( function( $title ) {
				return $title->getArticleID();
			}, $titles );
			wfDebugLog( 'CirrusSearchChangeFailed', "Other Index $actionName for article ids: " .
				implode( ',', $articleIDs ) );
			return false;
		}
	}
}
