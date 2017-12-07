<?php

namespace CirrusSearch\Search;

use CirrusSearch\Connection;
use Elastica\Query;
use Elastica\Search;
use Elastica\Type;
use MediaWiki\Logger\LoggerFactory;

/**
 * Class SearchRequestBuilder
 *
 * Build the search request body
 */
class SearchRequestBuilder {
	/** @var SearchContext */
	private $searchContext;

	/** @var Connection */
	private $connection;

	/** @var string  */
	private $indexBaseName;

	/** @var  int  */
	private $offset = 0;

	/** @var  int  */
	private $limit = 20;

	/** @var string search timeout, string with time and unit, e.g. 20s for 20 seconds*/
	private $timeout;

	/** @var boolean set to true when explanation is needed */
	private $returnExplain;

	/** @var Type force the type when set, use {@link Connection::pickIndexTypeForNamespaces}
	 * otherwise */
	private $pageType;

	/** @var string set the sort option, controls the use of rescore functions or elastic sort */
	private $sort = 'relevance';

	public function __construct( SearchContext $searchContext, Connection $connection, $indexBaseName ) {
		$this->searchContext = $searchContext;
		$this->connection = $connection;
		$this->indexBaseName = $indexBaseName;
	}

	/**
	 * Build the search request
	 * @return Search
	 */
	public function build() {
		$resultsType = $this->searchContext->getResultsType();

		$query = new Query();
		$query->setSource( $resultsType->getSourceFiltering() );
		$query->setStoredFields( $resultsType->getStoredFields() );

		$extraIndexes = $this->searchContext->getExtraIndices();

		if ( !empty( $extraIndexes ) ) {
			$this->searchContext->addNotFilter( new \Elastica\Query\Term(
				[ 'local_sites_with_dupe' => $this->indexBaseName ]
			) );
		}

		$query->setQuery( $this->searchContext->getQuery() );

		foreach ( $this->searchContext->getAggregations() as $agg ) {
			$query->addAggregation( $agg );
		}

		$highlight = $this->searchContext->getHighlight( $resultsType );
		if ( $highlight ) {
			$query->setHighlight( $highlight );
		}

		if ( $this->searchContext->getSuggest() ) {
			if ( interface_exists( 'Elastica\\ArrayableInterface' ) ) {
				// Elastica 2.3.x.  For some reason it unwraps our suggest
				// query when we don't want it to, so wrap it one more time
				// to make the unwrap do nothing.
				$query->setParam( 'suggest', [
					'suggest' => $this->searchContext->getSuggest()
				] );
			} else {
				$query->setParam( 'suggest', $this->searchContext->getSuggest() );
			}
			$query->addParam( 'stats', 'suggest' );
		}
		if ( $this->offset ) {
			$query->setFrom( $this->offset );
		}
		if ( $this->limit ) {
			$query->setSize( $this->limit );
		}

		foreach ( $this->searchContext->getSyntaxUsed() as $syntax ) {
			$query->addParam( 'stats', $syntax );
		}
		switch ( $this->sort ) {
			case 'just_match':
				// Use just matching scores, without any rescoring, and default sort.
				break;
			case 'relevance':
				$rescores = $this->searchContext->getRescore();
				if ( $rescores !== [] ) {
					$query->setParam( 'rescore', $rescores );
				}
				break;  // The default
			case 'title_asc':
				$query->setSort( [ 'title.keyword' => 'asc' ] );
				break;
			case 'title_desc':
				$query->setSort( [ 'title.keyword' => 'desc' ] );
				break;
			case 'incoming_links_asc':
				$query->setSort( [ 'incoming_links' => [
					'order' => 'asc',
					'missing' => '_first',
				] ] );
				break;
			case 'incoming_links_desc':
				$query->setSort( [ 'incoming_links' => [
					'order' => 'desc',
					'missing' => '_last',
				] ] );
				break;
			case 'none':
				$query->setSort( [ '_doc' ] );
				break;
			default:
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Invalid sort type: {sort}",
					[ 'sort' => $this->sort ]
				);
		}

		// Setup the search
		$queryOptions = [];
		if ( $this->timeout ) {
			$queryOptions[\Elastica\Search::OPTION_TIMEOUT] = $this->timeout;
		}
		// @todo when switching to multi-search this has to be provided at the top level
		if ( $this->searchContext->getConfig()->get( 'CirrusSearchMoreAccurateScoringMode' ) ) {
			$queryOptions[\Elastica\Search::OPTION_SEARCH_TYPE] = \Elastica\Search::OPTION_SEARCH_TYPE_DFS_QUERY_THEN_FETCH;
		}

		$pageType = $this->getPageType();

		$search = $pageType->createSearch( $query, $queryOptions );
		foreach ( $extraIndexes as $i ) {
			$search->addIndex( $i );
		}

		if ( $this->returnExplain ) {
			$query->setExplain( true );
		}

		return $search;
	}

	/**
	 * @return int
	 */
	public function getOffset() {
		return $this->offset;
	}

	/**
	 * @param int $offset
	 * @return SearchRequestBuilder
	 */
	public function setOffset( $offset ) {
		$this->offset = $offset;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getLimit() {
		return $this->limit;
	}

	/**
	 * @param int $limit
	 * @return SearchRequestBuilder
	 */
	public function setLimit( $limit ) {
		$this->limit = $limit;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getTimeout() {
		return $this->timeout;
	}

	/**
	 * @param string $timeout
	 * @return SearchRequestBuilder
	 */
	public function setTimeout( $timeout ) {
		$this->timeout = $timeout;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isReturnExplain() {
		return $this->returnExplain;
	}

	/**
	 * @param bool $returnExplain
	 * @return SearchRequestBuilder
	 */
	public function setReturnExplain( $returnExplain ) {
		$this->returnExplain = $returnExplain;

		return $this;
	}

	/**
	 * @return Type
	 */
	public function getPageType() {
		if ( $this->pageType ) {
			return $this->pageType;
		} else {
			$indexType = $this->connection->pickIndexTypeForNamespaces(
				$this->searchContext->getNamespaces() );
			return $this->connection->getPageType( $this->indexBaseName, $indexType );
		}
	}

	/**
	 * @param Type|null $pageType
	 * @return SearchRequestBuilder
	 */
	public function setPageType( $pageType ) {
		$this->pageType = $pageType;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSort() {
		return $this->sort;
	}

	/**
	 * @param string $sort
	 * @return SearchRequestBuilder
	 */
	public function setSort( $sort ) {
		$this->sort = $sort;

		return $this;
	}

	/**
	 * @return SearchContext
	 */
	public function getSearchContext() {
		return $this->searchContext;
	}
}
