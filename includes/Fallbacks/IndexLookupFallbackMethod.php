<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\InterwikiResolver;
use CirrusSearch\Parser\AST\Visitor\QueryFixer;
use CirrusSearch\Profile\ArrayPathSetter;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\CirrusSearchResultSet;
use CirrusSearch\Search\SearchQuery;
use Elastica\Client;
use Elastica\Query;
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
	 * @var array
	 */
	private $queryTemplate;

	/**
	 * @var string[]
	 */
	private $queryParams;

	/**
	 * @var string
	 */
	private $suggestionField;

	/**
	 * @var array
	 */
	private $profileParams;

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
		$queryParams = array_map(
			function ( $v ) {
				switch ( $v ) {
					case 'query':
						return $this->queryFixer->getFixablePart();
					case 'wiki':
						return $this->query->getSearchConfig()->getWikiId();
					default:
						return $this->extractParam( $v );
				}
			},
			$this->queryParams
		);
		$arrayPathSetter = new ArrayPathSetter( $queryParams );
		$query = $arrayPathSetter->transform( $this->queryTemplate );
		$query = new Query( [ 'query' => $query ] );
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
	 * @param string $keyAndValue
	 * @return mixed
	 */
	private function extractParam( $keyAndValue ) {
		$ar = explode( ':', $keyAndValue, 2 );
		if ( count( $ar ) != 2 ) {
			throw new SearchProfileException( "Invalid profile parameter [$keyAndValue]" );
		}
		list( $key, $value ) = $ar;
		switch ( $key ) {
			case 'params':
				$paramValue = $this->profileParams[$value] ?? null;
				if ( $paramValue == null ) {
					throw new SearchProfileException( "Missing profile parameter [$value]" );
				}
				return $paramValue;
			default:
				throw new SearchProfileException( "Unsupported profile parameter type [$key]" );
		}
	}

	/**
	 * @param SearchQuery $query
	 * @param string $index
	 * @param array $queryTemplate
	 * @param string $suggestionField
	 * @param string[] $queryParams
	 * @param array $profileParams
	 */
	public function __construct(
		SearchQuery $query,
		$index,
		$queryTemplate,
		$suggestionField,
		array $queryParams,
		array $profileParams
	) {
		$this->query = $query;
		$this->index = $index;
		$this->queryTemplate = $queryTemplate;
		$this->suggestionField = $suggestionField;
		$this->queryParams = $queryParams;
		$this->profileParams = $profileParams;
		$this->queryFixer = QueryFixer::build( $this->query->getParsedQuery() );
	}

	/**
	 * @param SearchQuery $query
	 * @param array $params
	 * @param InterwikiResolver|null $interwikiResolver
	 * @return FallbackMethod|null the method instance or null if unavailable
	 */
	public static function build( SearchQuery $query, array $params, InterwikiResolver $interwikiResolver = null ) {
		if ( !$query->isWithDYMSuggestion() ) {
			return null;
		}
		// TODO: Should this be tested at an upper level?
		if ( $query->getOffset() !== 0 ) {
			return null;
		}
		if ( !isset( $params['profile'] ) ) {
			throw new SearchProfileException( "Missing mandatory field profile" );
		}

		$profileParams = $params['profile_params'] ?? [];

		$profile = $query->getSearchConfig()->getProfileService()
			->loadProfileByName( SearchProfileService::INDEX_LOOKUP_FALLBACK, $params['profile'] );

		return new self( $query, $profile['index'], $profile['query'], $profile['suggestion_field'], $profile['params'], $profileParams );
	}

	/**
	 * @param FallbackRunnerContext $context
	 * @return float
	 */
	public function successApproximation( FallbackRunnerContext $context ) {
		$rset = $this->extractMethodResponse( $context );
		if ( $rset === null || $rset->getResults() === [] ) {
			return 0.0;
		}
		$suggestion = $rset->getResults()[0]->getFields()[$this->suggestionField][0] ?? null;
		if ( $suggestion === null ) {
			return 0.0;
		}
		return 0.5;
	}

	/**
	 * @param FallbackRunnerContext $context
	 * @return \Elastica\ResultSet|null null if there are no response or no results
	 */
	private function extractMethodResponse( FallbackRunnerContext $context ) {
		if ( !$context->hasMethodResponse() ) {
			return null;
		}

		return $context->getMethodResponse();
	}

	/**
	 * Rewrite the results,
	 * A costly call is allowed here, if nothing is to be done $previousSet
	 * must be returned.
	 *
	 * @param FallbackRunnerContext $context
	 * @return CirrusSearchResultSet
	 */
	public function rewrite( FallbackRunnerContext $context ): CirrusSearchResultSet {
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
