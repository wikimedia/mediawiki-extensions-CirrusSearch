<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Parser\AST\Visitor\QueryFixer;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchQuery;
use Elastica\Client;
use Elastica\Query;
use Elastica\Query\Match;
use Elastica\Search;

class IndexLookupFallbackMethod implements FallbackMethod, ElasticSearchRequestFallbackMethod {
	use FallbackMethodTrait;

	/**
	 * @var SearchQuery
	 */
	private $query;

	/**
	 * @var string
	 */
	private $index;

	/**
	 * @var string
	 */
	private $queryField;

	/**
	 * @var string
	 */
	private $suggestionField;

	/**
	 * @var QueryFixer
	 */
	private $queryFixer;

	/**
	 * @param Client $client
	 * @return Search|null null if no additional request is to be executed for this method.
	 * @see FallbackRunnerContext::getMethodResponse()
	 */
	public function getSearchRequest( Client $client ) {
		$fixablePart = $this->queryFixer->getFixablePart();
		if ( $fixablePart === null ) {
			return null;
		}
		$query = new Query( new Match( $this->queryField, $fixablePart ) );
		$query->setFrom( 0 )
			->setSize( 1 )
			->setSource( false )
			->setStoredFields( [ $this->suggestionField ] );
		$search = new Search( $client );
		$search->setQuery( $query )
			->addIndex( $this->index );
		return $search;
	}

	/**
	 * @param SearchQuery $query
	 * @param string $index
	 * @param string $queryField
	 * @param string $suggestionField
	 */
	public function __construct( SearchQuery $query, $index, $queryField, $suggestionField ) {
		$this->query = $query;
		$this->index = $index;
		$this->queryField = $queryField;
		$this->suggestionField = $suggestionField;
		$this->queryFixer = QueryFixer::build( $this->query->getParsedQuery() );
	}

	/**
	 * @param SearchQuery $query
	 * @param array $params
	 * @return FallbackMethod|null the method instance or null if unavailable
	 */
	public static function build( SearchQuery $query, array $params ) {
		if ( !$query->isWithDYMSuggestion() ) {
			return null;
		}
		// TODO: Should this be tested at an upper level?
		if ( $query->getOffset() !== 0 ) {
			return null;
		}

		$missingFields = array_diff_key( array_flip( [ 'index', 'query_field', 'suggestion_field' ] ), $params );
		if ( $missingFields !== [] ) {
			throw new SearchProfileException( "Missing mandatory fields: " . implode( ', ', array_keys( $missingFields ) ) );
		}

		return new self( $query, $params['index'], $params['query_field'], $params['suggestion_field'] );
	}

	/**
	 * @param FallbackRunnerContext $context
	 * @return float
	 */
	public function successApproximation( FallbackRunnerContext $context ) {
		$rset = $context->getMethodResponse();
		if ( $rset->getResults() === [] ) {
			return 0.0;
		}
		$suggestion = $rset->getResults()[0]->getFields()[$this->suggestionField][0] ?? null;
		if ( $suggestion === null ) {
			return 0.0;
		}
		return 0.5;
	}

	/**
	 * Rewrite the results,
	 * A costly call is allowed here, if nothing is to be done $previousSet
	 * must be returned.
	 *
	 * @param FallbackRunnerContext $context
	 * @return ResultSet
	 */
	public function rewrite( FallbackRunnerContext $context ): ResultSet {
		$previousSet = $context->getPreviousResultSet();
		if ( !$context->costlyCallAllowed() ) {
			// a method rewrote the query before us.
			return $previousSet;
		}
		if ( $previousSet->getSuggestionQuery() !== null ) {
			// a method suggested something before us
			return $previousSet;
		}
		$resultSet = $context->getMethodResponse();
		if ( empty( $resultSet->getResults() ) ) {
			return $previousSet;
		}
		$res = $resultSet->getResults()[0];
		$suggestedQuery = $res->getFields()[$this->suggestionField][0] ?? null;
		if ( $suggestedQuery === null ) {
			return $previousSet;
		}
		// Show the suggestion
		$previousSet->setRewrittenQuery( $suggestedQuery );
		// Maybe rewrite
		return $this->maybeSearchAndRewrite( $context, $this->query, $suggestedQuery );
	}
}
