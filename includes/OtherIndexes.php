<?php

namespace CirrusSearch;

use MediaWiki\Logger\LoggerFactory;
use Elastica\Multi\Search as MultiSearch;
use Title;

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
	 * @param Connection $connection
	 * @param SearchConfig $config
	 * @param array $flags
	 * @param string $localSite
	 */
	public function __construct( Connection $connection, SearchConfig $config, array $flags, $localSite ) {
		parent::__construct( $connection, $config, $flags );
		$this->localSite = $localSite;
	}

	/**
	 * Get the external index identifiers for title.
	 * @param SearchConfig $config
	 * @param Title $title
	 * @return ExternalIndex[] array of external indices.
	 */
	public static function getExternalIndexes( SearchConfig $config, Title $title ) {
		$namespace = $title->getNamespace();
		$indices = [];
		foreach ( $config->get( 'CirrusSearchExtraIndexes' )[$namespace] ?? [] as $indexName ) {
			$indices[] = new ExternalIndex( $config, $indexName );
		}
		return $indices;
	}

	/**
	 * Get any extra indexes to query, if any, based on namespaces
	 * @param SearchConfig $config
	 * @param int[] $namespaces An array of namespace ids
	 * @return ExternalIndex[] array of indexes
	 */
	public static function getExtraIndexesForNamespaces( SearchConfig $config, array $namespaces ) {
		$extraIndexes = [];
		foreach ( $config->get( 'CirrusSearchExtraIndexes' ) ?: [] as $namespace => $indexes ) {
			if ( !in_array( $namespace, $namespaces ) ) {
				continue;
			}
			foreach ( $indexes as $indexName ) {
				$extraIndexes[] = new ExternalIndex( $config, $indexName );
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

		$updates = [];

		// Build multisearch to find ids to update
		$findIdsMultiSearch = new MultiSearch( $this->connection->getClient() );
		$findIdsClosures = [];
		$readClusterName = $this->connection->getClusterName();
		foreach ( $titles as $title ) {
			foreach ( self::getExternalIndexes( $this->searchConfig, $title ) as $otherIndex ) {
				$searchIndex = $otherIndex->getSearchIndex( $readClusterName );
				$type = $this->connection->getPageType( $searchIndex );
				$query = $this->queryForTitle( $title );
				$search = $type->createSearch( $query );
				$findIdsMultiSearch->addSearch( $search );
				$findIdsClosures[] = function ( $docId ) use ( $otherIndex, &$updates, $title ) {
					$updates[$otherIndex->getIndexName()][] = [
						'docId' => $docId,
						'ns' => $title->getNamespace(),
						'dbKey' => $title->getDBkey(),
					];
				};
			}
		}
		$findIdsClosuresCount = count( $findIdsClosures );
		if ( $findIdsClosuresCount === 0 ) {
			// No other indexes to check.
			return;
		}

		// Look up the ids and run all closures to build the list of updates
		$this->start( new MultiSearchRequestLog(
			$this->connection->getClient(),
			'searching for {numIds} ids in other indexes',
			'other_idx_lookup',
			[ 'numIds' => $findIdsClosuresCount ]
		) );
		$findIdsMultiSearchResult = $findIdsMultiSearch->search();
		try {
			$this->success();
			foreach ( $findIdsClosures as $i => $closure ) {
				$results = $findIdsMultiSearchResult[$i]->getResults();
				if ( count( $results ) ) {
					$closure( $results[0]->getId() );
				}
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$this->failure( $e );
			return;
		}

		foreach ( $updates as $indexName => $actions ) {
			// These are split into a job per index so one index
			// being frozen doesn't block updates to other indexes
			// in the same update. Also because the external indexes
			// may be configured to write to different clusters.
			$job = Job\ElasticaWrite::build(
				reset( $titles ),
				'sendOtherIndexUpdates',
				[ $this->localSite, $indexName, $actions ],
				[ 'cluster' => $this->writeToClusterName, 'external-index' => $indexName ]
			);
			$job->run();
		}
	}

	/**
	 * @param Title[] $titles
	 * @param string $reason
	 */
	private function logFailure( array $titles, $reason = '' ) {
		$articleIDs = array_map( function ( Title $title ) {
			return $title->getArticleID();
		}, $titles );
		if ( $reason ) {
			$reason = " ($reason)";
		}
		LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->info(
			"Other Index$reason for article ids: " . implode( ',', $articleIDs ) );
	}

	/**
	 * @param Title $title
	 * @return \Elastica\Query
	 */
	private function queryForTitle( Title $title ) {
		$bool = new \Elastica\Query\BoolQuery();

		// Note that we need to use the keyword indexing of title so the analyzer gets out of the way.
		$bool->addFilter( new \Elastica\Query\Term( [ 'title.keyword' => $title->getText() ] ) );
		$bool->addFilter( new \Elastica\Query\Term( [ 'namespace' => $title->getNamespace() ] ) );

		$query = new \Elastica\Query( $bool );
		$query->setStoredFields( [] ); // We only need the _id so don't load the _source
		$query->setSize( 1 );

		return $query;
	}

}
