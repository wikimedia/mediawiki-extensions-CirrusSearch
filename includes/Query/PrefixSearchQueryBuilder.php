<?php

namespace CirrusSearch\Query;

use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SecondTry\SecondTryRunner;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Elastica\Query\MultiMatch;

/**
 * Build a query suited for autocomplete on titles+redirects
 */
class PrefixSearchQueryBuilder {
	use QueryBuilderTraits;

	private SecondTryRunner $secondTryRunner;

	public function __construct( SecondTryRunner $secondTryRunner ) {
		$this->secondTryRunner = $secondTryRunner;
	}

	/**
	 * @param SearchContext $searchContext
	 * @param string $term the original search term
	 * @param array<string, string[]>|null $precomputedSecondTryCandidates list of pre-computed second try candidates
	 */
	public function build( SearchContext $searchContext, string $term, ?array $precomputedSecondTryCandidates = null ): void {
		if ( !$this->checkTitleSearchRequestLength( $term, $searchContext ) ) {
			return;
		}
		$searchContext->setOriginalSearchTerm( $term );
		$searchContext->setProfileContext( SearchProfileService::CONTEXT_PREFIXSEARCH );
		$searchContext->addSyntaxUsed( 'prefix' );
		if ( strlen( $term ) > 0 ) {
			$secondTries = $precomputedSecondTryCandidates ?: $this->secondTryRunner->candidates( $term );
			if ( $searchContext->getConfig()->get( 'CirrusSearchPrefixSearchStartsWithAnyWord' ) ) {
				$searchContext->addFilter( $this->wordPrefixQuery( $term, $secondTries ) );
			} else {
				// TODO: weights should be a profile?
				$weights = $searchContext->getConfig()->get( 'CirrusSearchPrefixWeights' );
				$searchContext->setMainQuery( $this->keywordPrefixQuery( $term, $secondTries, $weights ) );
			}
		}
	}

	/**
	 * @param string $term
	 * @param array<string, string[]> $secondTries
	 * @return AbstractQuery
	 */
	private function wordPrefixQuery( string $term, array $secondTries ): AbstractQuery {
		$buildMatch = static function ( $searchTerm ) {
			$match = new MatchQuery();
			// TODO: redirect.title?
			$match->setField( 'title.word_prefix', [
				'query' => $searchTerm,
				'analyzer' => 'plain',
				'operator' => 'and',
			] );
			return $match;
		};
		$query = new BoolQuery();
		$query->setMinimumShouldMatch( 1 );
		$query->addShould( $buildMatch( $term ) );
		foreach ( $secondTries as $secondTry ) {
			foreach ( $secondTry as $variant ) {
				// This is a filter we don't really care about
				// discounting variant matches.
				$query->addShould( $buildMatch( $variant ) );
			}
		}
		return $query;
	}

	/**
	 * @param string $term
	 * @param array<string, string[]> $secondTries
	 * @param int[] $weights
	 * @return AbstractQuery
	 */
	private function keywordPrefixQuery( string $term, array $secondTries, array $weights ): AbstractQuery {
		// Elasticsearch seems to have trouble extracting the proper terms to highlight
		// from the default query we make so we feed it exactly the right query to highlight.
		$buildMatch = static function ( string $searchTerm, float $weight ) use ( $weights ): AbstractQuery {
			$query = new MultiMatch();
			$query->setQuery( $searchTerm );
			$query->setFields( [
				'title.prefix^' . ( $weights['title'] * $weight ),
				'redirect.title.prefix^' . ( $weights['redirect'] * $weight ),
				'title.prefix_asciifolding^' . ( $weights['title_asciifolding'] * $weight ),
				'redirect.title.prefix_asciifolding^' . ( $weights['redirect_asciifolding'] * $weight ),
			] );
			return $query;
		};
		$query = new BoolQuery();
		$query->setMinimumShouldMatch( 1 );
		$query->addShould( $buildMatch( $term, 1 ) );
		$candidateIndex = 0;
		foreach ( $secondTries as $strategy => $secondTry ) {
			$weight = $this->secondTryRunner->weight( $strategy );
			foreach ( $secondTry as $variant ) {
				$candidateIndex++;
				$query->addShould( $buildMatch( $variant, $weight * ( 0.2 ** $candidateIndex ) ) );
			}
		}
		return $query;
	}
}
