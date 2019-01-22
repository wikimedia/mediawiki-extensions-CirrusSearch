<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Parser\BasicQueryClassifier;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;

/**
 * Fallback method based the tlastic phrase suggester.
 * TODO: This is currently only handling the "rewrite" process using the suggestion
 * but in the future it'll be responsible from setting the suggestion in the ResultSet
 */
class PhraseSuggestFallbackMethod implements FallbackMethod {
	use FallbackMethodTrait;

	/**
	 * @var SearchQuery
	 */
	private $query;

	/**
	 * @var SearcherFactory
	 */
	private $searcherFactory;

	/**
	 * PhraseSuggestFallbackMethod constructor.
	 * @param SearcherFactory $factory
	 * @param SearchQuery $query
	 */
	public function __construct( SearcherFactory $factory, SearchQuery $query ) {
		$this->searcherFactory = $factory;
		$this->query = $query;
	}

	/**
	 * @param SearcherFactory $factory
	 * @param SearchQuery $query
	 * @param \WebRequest $request
	 * @return FallbackMethod
	 */
	public static function build( SearcherFactory $factory, SearchQuery $query, \WebRequest $request ) {
		return new self( $factory, $query );
	}

	/**
	 * @param ResultSet $firstPassResults
	 * @return float
	 */
	public function successApproximation( ResultSet $firstPassResults ) {
		if ( !$this->query->isAllowRewrite() ) {
			return 0.0;
		}

		if ( $this->resultsThreshold( $firstPassResults ) ) {
			return 0.0;
		}

		if ( !$this->query->getParsedQuery()->isQueryOfClass( BasicQueryClassifier::SIMPLE_BAG_OF_WORDS ) ) {
			return 0.0;
		}
		if ( $firstPassResults->hasSuggestion() ) {
			return 0.5;
		}
		return 0.0;
	}

	/**
	 * @param ResultSet $firstPassResults
	 * @param ResultSet $previousSet
	 * @return ResultSet
	 */
	public function rewrite( ResultSet $firstPassResults, ResultSet $previousSet ) {
		if ( $this->resultsThreshold( $previousSet ) ) {
			return $previousSet;
		}

		$rewrittenQuery = SearchQueryBuilder::forRewrittenQuery( $this->query,
			$firstPassResults->getSuggestionQuery() )->build();
		$searcher = $this->searcherFactory->makeSearcher( $rewrittenQuery );
		$status = $searcher->search( $rewrittenQuery );
		if ( $status->isOK() && $status->getValue() instanceof ResultSet ) {
			/**
			 * @var ResultSet $newresults
			 */
			$newresults = $status->getValue();
			$newresults->setRewrittenQuery( $firstPassResults->getSuggestionQuery(),
				$firstPassResults->getSuggestionSnippet() );
			return $newresults;
		} else {
			return $previousSet;
		}
	}
}
