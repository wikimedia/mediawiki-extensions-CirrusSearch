<?php

namespace CirrusSearch\MetaStore;

use Elastica\Document;
use Elastica\Query\BoolQuery;
use Elastica\Query\Ids;
use Elastica\Query\MatchQuery;
use Elastica\Type;
use WikiMap;

class MetaNamespaceStore implements MetaStore {
	/** @const Value of metastore 'type' field for our documents */
	public const METASTORE_TYPE = 'namespace';

	/** @var Type */
	private $elasticaType;

	/** @var string */
	private $wikiId;

	public function __construct( Type $elasticaType, $wikiId = null ) {
		$this->elasticaType = $elasticaType;
		$this->wikiId = $wikiId ?? WikiMap::getCurrentWikiId();
	}

	/**
	 * @param string $wikiId Wiki namespace belongs to
	 * @param int $nsId Id of the namespace
	 * @return string Metastore document id
	 */
	public static function docId( $wikiId, $nsId ) {
		return implode( '-', [
			self::METASTORE_TYPE,
			$wikiId, $nsId
		] );
	}

	/**
	 * @return array Custom field mappings for metastore index
	 */
	public function buildIndexProperties() {
		return [
			'namespace_name' => [
				'type' => 'text',
				'analyzer' => 'near_match_asciifolding',
				'norms' => false,
				'index_options' => 'docs',
			],
		];
	}

	/**
	 * Delete and re-index all namespaces for current wiki
	 *
	 * @param \Language $lang Content language of the wiki
	 */
	public function reindex( \Language $lang ) {
		$documents = $this->buildDocuments( $lang );
		$docIds = [];
		foreach ( $documents as $doc ) {
			$docIds[] = $doc->getId();
		}
		$this->elasticaType->deleteByQuery( \Elastica\Query::create(
			$this->queryFilter()->addMustNot( new Ids( $docIds ) ) ) );
		$this->elasticaType->addDocuments( $documents );
	}

	/**
	 * Find namespaces on the current wiki very similar to $name.
	 * Invoking from a user request must be gated on a PoolCounter.
	 *
	 * @param string $name Namespace to search for
	 * @param array $queryOptions Query parameters to send to elasticsearch
	 * @return \Elastica\ResultSet
	 */
	public function find( $name, array $queryOptions = [] ) {
		$bool = $this->queryFilter();
		$bool->addMust( ( new MatchQuery() )
			->setField( 'namespace_name', $name ) );
		$query = ( new \Elastica\Query( $bool ) )
			->setParam( '_source', [ 'namespace_id' ] )
			->setParam( 'stats', [ 'namespace' ] );

		return $this->elasticaType->search( $query, $queryOptions );
	}

	private function queryFilter() {
		return ( new BoolQuery() )
			->addFilter( new MatchQuery( 'type', self::METASTORE_TYPE ) )
			->addFilter( new MatchQuery( 'wiki', $this->wikiId ) );
	}

	private function buildDocuments( \Language $lang ) {
		$namesByNsId = [];
		foreach ( $lang->getNamespaceIds() as $name => $nsId ) {
			if ( $name ) {
				$namesByNsId[$nsId][] = $name;
			}
		}
		$documents = [];
		foreach ( $namesByNsId as $nsId => $names ) {
			$documents[] = new Document( self::docId( $this->wikiId, $nsId ), [
				'type' => self::METASTORE_TYPE,
				'wiki' => $this->wikiId,
				'namespace_id' => $nsId,
				'namespace_name' => $names,
			] );
		}
		return $documents;
	}
}
