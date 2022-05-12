<?php

namespace CirrusSearch\MetaStore;

use CirrusSearch\Connection;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\ArchiveMappingConfigBuilder;
use CirrusSearch\Maintenance\MappingConfigBuilder;
use CirrusSearch\Maintenance\SuggesterAnalysisConfigBuilder;
use CirrusSearch\Maintenance\SuggesterMappingConfigBuilder;
use Elastica\Index;
use Elastica\Query\BoolQuery;
use GitInfo;
use WikiMap;

class MetaVersionStore implements MetaStore {
	public const METASTORE_TYPE = 'version';

	/** @var Connection */
	private $connection;

	/** @var Index */
	private $index;

	public function __construct( Index $index, Connection $connection ) {
		$this->index = $index;
		$this->connection = $connection;
	}

	/**
	 * @param Connection $connection
	 * @param string $baseName
	 * @param string $typeName
	 * @return string
	 */
	public static function docId( Connection $connection, $baseName, $typeName ) {
		return implode( '-', [
			self::METASTORE_TYPE,
			$connection->getIndexName( $baseName, $typeName )
		] );
	}

	/**
	 * @return array Properties to add to metastore for version info
	 */
	public function buildIndexProperties() {
		return [
			'index_name' => [ 'type' => 'keyword' ],
			'analysis_maj' => [ 'type' => 'long' ],
			'analysis_min' => [ 'type' => 'long' ],
			'mapping_maj' => [ 'type' => 'long' ],
			'mapping_min' => [ 'type' => 'long' ],
			'shard_count' => [ 'type' => 'long' ],
			'mediawiki_version' => [ 'type' => 'keyword' ],
			'mediawiki_commit' => [ 'type' => 'keyword' ],
			'cirrus_commit' => [ 'type' => 'keyword' ],
		];
	}

	/**
	 * @param string $baseName
	 * @param string $typeName
	 */
	public function update( $baseName, $typeName ) {
		$this->index->addDocuments( [ self::buildDocument( $this->connection, $baseName, $typeName ) ] );
	}

	/**
	 * @param string $baseName
	 */
	public function updateAll( $baseName ) {
		$docs = [];
		foreach ( $this->connection->getAllIndexSuffixes( null ) as $typeName ) {
			$docs[] = self::buildDocument( $this->connection, $baseName, $typeName );
		}
		$this->index->addDocuments( $docs );
	}

	/**
	 * @param string $baseName
	 * @param string $typeName
	 * @return \Elastica\Document
	 */
	public function find( $baseName, $typeName ) {
		$docId = self::docId( $this->connection, $baseName, $typeName );
		return $this->index->getDocument( $docId );
	}

	/**
	 * @param string|null $baseName Base index name to find, or all to
	 *  return all indices for all wikis.
	 * @return \Elastica\ResultSet
	 */
	public function findAll( $baseName = null ) {
		$filter = new BoolQuery();
		$filter->addFilter( ( new \Elastica\Query\Term() )
			->setTerm( 'type', self::METASTORE_TYPE ) );
		if ( $baseName !== null ) {
			$ids = new \Elastica\Query\Ids();
			foreach ( $this->connection->getAllIndexSuffixes( null ) as $typeName ) {
				$ids->addId( self::docId( $this->connection, $baseName, $typeName ) );
			}
			$filter->addFilter( $ids );
		}

		$query = new \Elastica\Query( $filter );
		// WHAT ARE YOU DOING TRACKING MORE THAN 5000 INDICES?!?
		$query->setSize( 5000 );
		return $this->index->search( $query );
	}

	/**
	 * Create version data for index type.
	 * @param Connection $connection
	 * @param string $baseName
	 * @param string $typeName
	 * @return \Elastica\Document
	 */
	public static function buildDocument( Connection $connection, $baseName, $typeName ) {
		global $IP;
		if ( $typeName == Connection::TITLE_SUGGEST_INDEX_SUFFIX ) {
			list( $aMaj, $aMin ) = explode( '.', SuggesterAnalysisConfigBuilder::VERSION, 3 );
			list( $mMaj, $mMin ) = explode( '.', SuggesterMappingConfigBuilder::VERSION, 3 );
		} elseif ( $typeName === Connection::ARCHIVE_INDEX_SUFFIX ) {
			list( $aMaj, $aMin ) = explode( '.', AnalysisConfigBuilder::VERSION, 3 );
			list( $mMaj, $mMin ) = explode( '.', ArchiveMappingConfigBuilder::VERSION, 3 );
		} else {
			list( $aMaj, $aMin ) = explode( '.', AnalysisConfigBuilder::VERSION, 3 );
			list( $mMaj, $mMin ) = explode( '.', MappingConfigBuilder::VERSION, 3 );
		}
		$mwInfo = new GitInfo( $IP );
		$cirrusInfo = new GitInfo( __DIR__ . '/../..' );
		$docId = self::docId( $connection, $baseName, $typeName );
		$data = [
			'type' => self::METASTORE_TYPE,
			'wiki' => WikiMap::getCurrentWikiId(),
			'index_name' => $connection->getIndexName( $baseName, $typeName ),
			'analysis_maj' => $aMaj,
			'analysis_min' => $aMin,
			'mapping_maj' => $mMaj,
			'mapping_min' => $mMin,
			'shard_count' => $connection->getSettings()->getShardCount( $typeName ),
			'mediawiki_version' => MW_VERSION,
			'mediawiki_commit' => $mwInfo->getHeadSHA1(),
			'cirrus_commit' => $cirrusInfo->getHeadSHA1(),
		];

		return new \Elastica\Document( $docId, $data, '_doc' );
	}
}
