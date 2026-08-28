<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MatchNone;

/**
 * Simple Match query builder, currently based on
 * FullTextQueryStringQueryBuilder to reuse its parsing logic.
 * It will only support queries that do not use the lucene QueryString syntax
 * and fallbacks to FullTextQueryStringQueryBuilder in such cases.
 * It generates only simple match/multi_match queries. It supports merging
 * multiple clauses into a dismax query with 'in_dismax'.
 */
class FullTextSimpleMatchQueryBuilder extends FullTextQueryStringQueryBuilder {
	/**
	 * @var bool true is the main used the experimental query
	 */
	private $usedExpQuery = false;

	/**
	 * @var float[]|array[] mixed array of field settings used for the main query
	 */
	private $fields;

	/**
	 * @var float[]|array[] mixed array of field settings used for the phrase rescore query
	 */
	private $phraseFields;

	/**
	 * @var float default weight to use for stems
	 */
	private $defaultStemWeight;

	/**
	 * @var string default multimatch query type
	 */
	private $defaultQueryType;

	/**
	 * @var string default multimatch min should match
	 */
	private $defaultMinShouldMatch;

	/**
	 * @var array[] dismax query settings
	 */
	private $dismaxSettings;

	/**
	 * @var array filter settings
	 */
	private $filter;

	public function __construct( SearchConfig $config, array $feature, array $settings ) {
		parent::__construct( $config, $feature );
		$this->fields = $settings['fields'];
		$this->filter = $settings['filter'] ?? [ 'type' => 'default' ];
		$this->phraseFields = $settings['phrase_rescore_fields'];
		$this->defaultStemWeight = $settings['default_stem_weight'];
		$this->defaultQueryType = $settings['default_query_type'];
		$this->defaultMinShouldMatch = $settings['default_min_should_match'];
		$this->dismaxSettings = $settings['dismax_settings'] ?? [];
	}

	/**
	 * Build the primary query used for full text search.
	 * If query_string syntax is not used the experimental query is built.
	 * We fallback to parent implementation otherwise.
	 *
	 * @param SearchContext $context
	 * @param string[] $fields
	 * @param AbstractQuery $nearMatchQuery
	 * @param string $queryString
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildSearchTextQuery(
		SearchContext $context,
		array $fields,
		AbstractQuery $nearMatchQuery,
		$queryString
	) {
		if ( $context->isSyntaxUsed( 'query_string' ) ) {
			return parent::buildSearchTextQuery( $context, $fields,
				$nearMatchQuery, $queryString );
		}
		$context->addSyntaxUsed( 'full_text_simple_match', 5 );
		$this->usedExpQuery = true;
		$queryForMostFields = $this->buildExpQuery( $context, $queryString );
		if ( $nearMatchQuery instanceof MatchNone ) {
			return $queryForMostFields;
		}

		// Build one query for the full text fields and one for the near match fields so that
		// the near match can run unescaped.
		$bool = new \Elastica\Query\BoolQuery();
		$bool->setMinimumShouldMatch( 1 );
		$bool->addShould( $queryForMostFields );
		$bool->addShould( $nearMatchQuery );

		return $bool;
	}

	/**
	 * Builds the highlight query
	 * @param SearchContext $context
	 * @param string[] $fields
	 * @param string $queryText
	 * @param int $slop
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildHighlightQuery( SearchContext $context, array $fields, $queryText, $slop ) {
		$query = parent::buildHighlightQuery( $context, $fields, $queryText, $slop );
		if ( $this->usedExpQuery && $query instanceof \Elastica\Query\QueryString ) {
			// the exp query accepts more docs (stopwords in query are not required)
			$query->setDefaultOperator( 'OR' );
		}
		return $query;
	}

	/**
	 * Builds the phrase rescore query
	 * @param SearchContext $context
	 * @param string[] $fields
	 * @param string $queryText
	 * @param int $slop
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildPhraseRescoreQuery( SearchContext $context, array $fields, $queryText, $slop ) {
		if ( $this->usedExpQuery ) {
			$phrase = new \Elastica\Query\MultiMatch();
			$phrase->setParam( 'type', 'phrase' );
			$phrase->setParam( 'slop', $slop );
			$fields = [];
			foreach ( $this->phraseFields as $f => $b ) {
				// The profile weights the all field as a whole. When it is substituted that
				// weight is carried onto each field it is built from.
				$substitute = self::allFieldSubstitute( $context, $f, (float)$b );
				if ( $substitute === null ) {
					$fields[] = "$f^$b";
				} else {
					$fields = array_merge( $fields, $substitute );
				}
			}
			$phrase->setFields( $fields );
			$phrase->setQuery( $queryText );
			return $this->maybeWrapWithTokenCountRouter( $queryText, $phrase );
		} else {
			return parent::buildPhraseRescoreQuery( $context, $fields, $queryText, $slop );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getMultiTermRewriteMethod() {
		// Use blended freq as a rewrite method. The
		// top_terms_boost_1024 method used by the parent is not well
		// suited for a weighted sum and for some reasons uses the
		// queryNorms which depends on the number of terms found by the
		// wildcard. Using this one we'll use the similarity configured
		// for this field instead of a constant score and in the case
		// of BM25 queryNorm is ignored (removed in lucene 7)
		return 'top_terms_blended_freqs_1024';
	}

	/**
	 * Generate an elasticsearch query by reading profile settings
	 * @param SearchContext $context
	 * @param string $queryString the query text
	 * @return \Elastica\Query\AbstractQuery
	 */
	private function buildExpQuery( SearchContext $context, $queryString ) {
		$query = new \Elastica\Query\BoolQuery();
		$query->setMinimumShouldMatch( 0 );
		$this->attachFilter( $context, $this->filter, $queryString, $query );
		$dismaxQueries = [];

		foreach ( $this->effectiveFields( $context ) as $f => $settings ) {
			$mmatch = new \Elastica\Query\MultiMatch();
			$mmatch->setQuery( $queryString );
			$queryType = $this->defaultQueryType;
			$minShouldMatch = $this->defaultMinShouldMatch;
			$stemWeight = $this->defaultStemWeight;
			$boost = 1;
			$plainBoost = 1;
			$fields = [ "$f.plain^1", "$f^$stemWeight" ];
			$in_dismax = null;

			if ( is_array( $settings ) ) {
				$stemWeight = $settings['stem_boost'] ?? $stemWeight;
				$plainBoost = $settings['plain_boost'] ?? $plainBoost;
				$boost = $settings['boost'] ?? $boost;

				$queryType = $settings['query_type'] ?? $queryType;
				$minShouldMatch = $settings['min_should_match'] ?? $minShouldMatch;
				if ( isset( $settings['is_plain'] ) && $settings['is_plain'] ) {
					$fields = [ $f ];
				} else {
					$fields = [ "$f.plain^$plainBoost", "$f^$stemWeight" ];
				}
				$in_dismax = $settings['in_dismax'] ?? null;
			} else {
				$boost = $settings;
			}

			if ( $boost === 0 ) {
				continue;
			}

			$mmatch->setParam( 'boost', $boost );
			$mmatch->setMinimumShouldMatch( $minShouldMatch );
			$mmatch->setType( $queryType );
			$mmatch->setFields( $fields );
			$mmatch->setParam( 'boost', $boost );
			$mmatch->setQuery( $queryString );
			if ( $in_dismax ) {
				$dismaxQueries[$in_dismax][] = $mmatch;
			} else {
				$query->addShould( $mmatch );
			}
		}
		foreach ( $dismaxQueries as $name => $queries ) {
			$dismax = new \Elastica\Query\DisMax();
			if ( isset( $this->dismaxSettings[$name] ) ) {
				$settings = $this->dismaxSettings[$name];
				if ( isset( $settings['tie_breaker'] ) ) {
					$dismax->setTieBreaker( $settings['tie_breaker'] );
				}
				if ( isset( $settings['boost'] ) ) {
					$dismax->setBoost( $settings['boost'] );
				}
			}
			foreach ( $queries as $q ) {
				$dismax->addQuery( $q );
			}
			$query->addShould( $dismax );
		}
		return $query;
	}

	/**
	 * The profile's scored fields, minus the ones built from the target's redirect array when
	 * the query's redirect mode must not read it. Returns a copy: this instance is reused
	 * across queries, so the profile's field set must survive untouched.
	 *
	 * @param SearchContext $context
	 * @return array
	 */
	private function effectiveFields( SearchContext $context ): array {
		return array_filter(
			$this->fields, [ $context->getRedirectMode(), 'allowsField' ], ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Attach the query filter to $boolQuery
	 *
	 * @param SearchContext $context
	 * @param array $filterDef filter definition
	 * @param string $query query text
	 * @param \Elastica\Query\BoolQuery $boolQuery the query to attach the filter to
	 */
	private function attachFilter(
		SearchContext $context, array $filterDef, $query, \Elastica\Query\BoolQuery $boolQuery
	) {
		if ( !isset( $filterDef['type'] ) ) {
			throw new \RuntimeException( "Cannot configure the filter clause, 'type' must be defined." );
		}
		$type = $filterDef['type'];
		$filter = null;

		switch ( $type ) {
			case 'default':
				$filter = $this->buildSimpleAllFilter( $context, $filterDef, $query );
				break;
			case 'constrain_title':
				$filter = $this->buildTitleFilter( $context, $filterDef, $query );
				break;
			default:
				throw new \RuntimeException( "Cannot build the filter clause: unknown filter type $type" );
		}

		$boolQuery->addFilter( $filter );
	}

	/**
	 * Builds a simple filter on all and all.plain when all terms must match
	 *
	 * This clause decides recall. all is a copy_to of every scored field, redirect.title
	 * included, so in a mode where the redirect array must take no part each all field is
	 * replaced by the fields it is built from, minus the redirect ones. Without that a page
	 * whose only match is one of its redirects still passes the filter.
	 *
	 * @param SearchContext $context
	 * @param array[] $options array containing filter options
	 * @param string $query
	 * @return \Elastica\Query\AbstractQuery
	 */
	private function buildSimpleAllFilter( SearchContext $context, $options, $query ) {
		$filter = new \Elastica\Query\BoolQuery();
		$filter->setMinimumShouldMatch( 1 );
		// FIXME: We can't use solely the stem field here
		// - Depending on languages it may lack stopwords,
		// A dedicated field used for filtering would be nice
		foreach ( [ 'all', 'all.plain' ] as $field ) {
			$minShouldMatch = $options['settings'][$field]['minimum_should_match'] ?? '100%';
			$substitute = self::allFieldSubstitute( $context, $field );
			if ( $substitute === null ) {
				$m = new \Elastica\Query\MatchQuery();
				$m->setFieldQuery( $field, $query );
				if ( $minShouldMatch === '100%' ) {
					$m->setFieldOperator( $field, 'AND' );
				} else {
					$m->setFieldMinimumShouldMatch( $field, $minShouldMatch );
				}
			} else {
				$m = new \Elastica\Query\MultiMatch();
				// cross_fields treats the group as one big field, which is what the all
				// field simulates at index time.
				$m->setType( \Elastica\Query\MultiMatch::TYPE_CROSS_FIELDS );
				$m->setFields( $substitute );
				$m->setQuery( $query );
				if ( $minShouldMatch === '100%' ) {
					$m->setOperator( 'AND' );
				} else {
					$m->setMinimumShouldMatch( $minShouldMatch );
				}
			}
			$filter->addShould( $m );
		}
		return $filter;
	}

	/**
	 * @param SearchContext $context
	 * @param string $allField Either 'all' or 'all.plain'.
	 * @param float $weight Boost applied to the all field as a whole, carried onto each field
	 *  it is built from.
	 * @return string[]|null The fields to query in place of $allField, each already carrying
	 *  its boost, or null when the all field itself may be used.
	 */
	private static function allFieldSubstitute(
		SearchContext $context, string $allField, float $weight = 1
	): ?array {
		if ( $context->getRedirectMode()->queriesRedirectArray() ) {
			return null;
		}
		$suffix = $allField === 'all.plain' ? '.plain' : '';
		return self::buildFullTextSearchFields( $context, $weight, $suffix, false );
	}

	/**
	 * Builds a simple filter based on buildSimpleAllFilter + a constraint
	 * on title/redirect :
	 * (all:query OR all.plain:query) AND (title:query OR redirect:query)
	 * where the filter on title/redirect can be controlled by setting
	 * minimum_should_match to relax the constraint on title.
	 * (defaults to '3<80%')
	 *
	 * @param SearchContext $context
	 * @param array[] $options array containing filter options
	 * @param string $query the user query
	 * @return \Elastica\Query\AbstractQuery
	 */
	private function buildTitleFilter( SearchContext $context, $options, $query ) {
		$filter = new \Elastica\Query\BoolQuery();
		$filter->addMust( $this->buildSimpleAllFilter( $context, $options, $query ) );
		$minShouldMatch = $options['settings']['minimum_should_match'] ?? '3<80%';
		$titleFilter = new \Elastica\Query\BoolQuery();
		$titleFilter->setMinimumShouldMatch( 1 );

		$redirectMode = $context->getRedirectMode();
		foreach ( [ 'title', 'redirect.title' ] as $field ) {
			if ( !$redirectMode->allowsField( $field ) ) {
				continue;
			}
			$m = new \Elastica\Query\MatchQuery();
			$m->setFieldQuery( $field, $query );
			$m->setFieldMinimumShouldMatch( $field, $minShouldMatch );
			$titleFilter->addShould( $m );
		}
		$filter->addMust( $titleFilter );
		return $filter;
	}
}
