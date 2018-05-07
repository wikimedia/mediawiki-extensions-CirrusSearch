<?php

namespace CirrusSearch;

use Elastica\Document;
use Elastica\Query\Match;
use Elastica\Query\MatchAll;

class NamespaceStore {
	/** @var SearchConfig */
	private $searchConfig;

	/** @var Connection */
	private $connection;

	public function __construct( Connection $connection, SearchConfig $searchConfig ) {
		$this->searchConfig = $searchConfig;
		$this->connection = $connection;
	}

	public function reindex( \Language $lang ) {
		$this->getType()->deleteByQuery( \Elastica\Query::create( new MatchAll() ) );
		$namesByNsId = [];
		foreach ( $lang->getNamespaceIds() as $name => $nsId ) {
			if ( $name ) {
				$namesByNsId[$nsId][] = $name;
			}
		}
		$documents = [];
		$wikiId = $this->searchConfig->getWikiId();
		foreach ( $namesByNsId as $nsId => $names ) {
			$documents[] = new Document( $nsId, [
				'name' => $names,
				'wiki' => $wikiId,
			] );
		}
		$this->getType()->addDocuments( $documents );
	}

	public function find( $name, $indexBaseName = null, array $queryOptions = [] ) {
		$match = ( new Match() )
			->setField( 'name', $name );
		$query = ( new \Elastica\Query( $match ) )
			->setParam( '_source', false )
			->setParam( 'stats', [ 'namespace' ] );

		return $this->getType( $indexBaseName )->search( $query, $queryOptions + [
			'search_type' => 'query_then_fetch',
		] );
	}

	private function getType( $indexBaseName = null ) {
		if ( $indexBaseName === null ) {
			$indexBaseName = $this->searchConfig->get( SearchConfig::INDEX_BASE_NAME );
		}
		return $this->connection->getNamespaceType( $indexBaseName );
	}
}
