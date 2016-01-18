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
	 * @param Connection $connection
	 * @param array $flags
	 * @param string $localSite
	 */
	public function __construct( Connection $connection, array $flags, $localSite) {
		parent::__construct( $connection, $flags );
		$this->localSite = $localSite;
	}

	/**
	 * Get the external index identifiers for title.
	 * @param Title $title
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
	 * Update the indexes for other wiki that also store information about $titles.
	 * @param Title[] $titles array of titles in other indexes to update
	 */
	public function updateOtherIndex( $titles ) {
		global $wgCirrusSearchWikimediaExtraPlugin;

		if ( !isset( $wgCirrusSearchWikimediaExtraPlugin['super_detect_noop'] ) ) {
			$this->logFailure( $titles, 'super_detect_noop plugin not enabled' );
			return;
		}


		$updates = array();

		// Build multisearch to find ids to update
		$findIdsMultiSearch = new \Elastica\Multi\Search( $this->connection->getClient() );
		$findIdsClosures = array();
		foreach ( $titles as $title ) {
			foreach ( OtherIndexes::getExternalIndexes( $title ) as $otherIndex ) {
				if ( $otherIndex === null ) {
					continue;
				}
				$type = $this->connection->getPageType( $otherIndex );
				$bool = new \Elastica\Filter\BoolFilter();
				// Note that we need to use the keyword indexing of title so the analyzer gets out of the way.
				$bool->addMust( new \Elastica\Filter\Term( array( 'title.keyword' => $title->getText() ) ) );
				$bool->addMust( new \Elastica\Filter\Term( array( 'namespace' => $title->getNamespace() ) ) );
				$filtered = new \Elastica\Query\Filtered( new \Elastica\Query\MatchAll(), $bool );
				$query = new \Elastica\Query( $filtered );
				$query->setFields( array() ); // We only need the _id so don't load the _source
				$query->setSize( 1 );
				$findIdsMultiSearch->addSearch( $type->createSearch( $query ) );
				$findIdsClosures[] = function( $id ) use
						( $otherIndex, &$updates, $title ) {
					$updates[$otherIndex][] = array(
						'id' => $id,
						'ns' => $title->getNamespace(),
						'dbKey' => $title->getDBkey(),
					);
				};
			}
		}
		$findIdsClosuresCount = count( $findIdsClosures );
		if ( $findIdsClosuresCount === 0 ) {
			// No other indexes to check.
			return;
		}

		// Look up the ids and run all closures to build the list of updates
		$this->start( "searching for {numIds} ids in other indexes", array(
			'numIds' => $findIdsClosuresCount,
			'queryType' => 'other_idx_lookup',
		) );
		$findIdsMultiSearchResult = $findIdsMultiSearch->search();
		try {
			$this->success();
			for ( $i = 0; $i < $findIdsClosuresCount; $i++ ) {
				$results = $findIdsMultiSearchResult[ $i ]->getResults();
				if ( count( $results ) === 0 ) {
					continue;
				}
				$result = $results[ 0 ];
				call_user_func( $findIdsClosures[ $i ], $result->getId() );
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$this->failure( $e );
			return;
		}

		if ( !$updates ) {
			return;
		}

		// These are split into a job per index so one index
		// being frozen doesn't block updates to other indexes
		// in the same update.
		foreach ( $updates as $indexName => $actions ) {
			$job = new Job\ElasticaWrite( reset( $titles ), array(
				'clientSideTimeout' => false,
				'method' => 'sendOtherIndexUpdates',
				'arguments' => array( $this->localSite, $indexName, $actions ),
				'cluster' => $this->writeToClusterName,
			) );
			$job->run();
		}
	}

	/**
	 * @param Title[] $titles
	 * @param string $reason
	 */
	private function logFailure( array $titles, $reason = '' ) {
		$articleIDs = array_map( function( Title $title ) {
			return $title->getArticleID();
		}, $titles );
		if ( $reason ) {
			$reason = " ($reason)";
		}
		LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->info(
			"Other Index$reason for article ids: " . implode( ',', $articleIDs ) );
	}
}
