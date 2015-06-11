<?php

namespace CirrusSearch;
use MediaWiki\Logger\LoggerFactory;
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
	 * @return string[] array of index identifiers.  empty means none.
	 */
	public static function getExternalIndexes( Title $title ) {
		global $wgCirrusSearchExtraIndexes;
		$namespace = $title->getNamespace();
		return isset( $wgCirrusSearchExtraIndexes[ $namespace ] )
			? $wgCirrusSearchExtraIndexes[ $namespace ] : array();
	}

	/**
	 * Get any extra indexes to query, if any, based on namespaces
	 * @param int[] $namespaces An array of namespace ids
	 * @return string[] array of indexes
	 */
	public static function getExtraIndexesForNamespaces( array $namespaces ) {
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
		$this->updateOtherIndex( 'addLocalSite', 'add', $titles );
	}

	/**
	 * Remove the local wiki from the duplicate tracking list on the indexes of other wikis for $titles.
	 * @param Title[] $titles array of titles for which to remove the tracking field
	 */
	public function removeLocalSiteFromOtherIndex( array $titles ) {
		$this->updateOtherIndex( 'removeLocalSite', 'remove', $titles );
	}

	/**
	 * Update the indexes for other wiki that also store information about $titles.
	 * @param string $actionName name of the action to report in logging
	 * @param string $setAction Set action to perform with super_detect_noop. Either 'add' or 'remove'
	 * @param Title[] $titles array of titles in other indexes to update
	 * @return bool false on failure, null otherwise
	 */
	private function updateOtherIndex( $actionName, $setAction, $titles ) {
		global $wgCirrusSearchWikimediaExtraPlugin;

		if ( !isset( $wgCirrusSearchWikimediaExtraPlugin['super_detect_noop'] ) ) {
			$this->logFailure( $actionName, $titles, 'super_detect_noop plugin not enabled' );
			return;
		}

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
						( $setAction, $bulk, $otherIndex, $localSite, &$updatesInBulk ) {
					$script = new \Elastica\Script(
						'super_detect_noop',
						array(
							'source' => array(
								'local_sites_with_dupe' => array( $setAction => $localSite ),
							),
							'handlers' => array( 'local_sites_with_dupe' => 'set' )
						),
						'native'
					);
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
			if ( $this->bulkResponseExceptionIsJustDocumentMissing( $e ) ) {
				$exception = $e;
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$exception = $e;
		}
		if ( $exception === null ) {
			$this->success();
		} else {
			$this->failure( $e );
			$this->logFailure( $actionName, $titles );
			return false;
		}
	}

	private function logFailure( $actionNAme, array $titles, $reason = '' ) {
		$articleIDs = array_map( function( $title ) {
			return $title->getArticleID();
		}, $titles );
		if ( $reason ) {
			$reason = " ($reason)";
		}
		LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->info(
			"Other Index $actionName$reason for article ids: " . implode( ',', $articleIDs ) );
	}
}
