<?php

namespace CirrusSearch\Query;

use CirrusSearch\Extra\Query\TokenCountRouter;
use CirrusSearch\Query\Builder\NearMatchFieldQueryBuilder;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MatchAll;
use Elastica\Query\MatchNone;
use MediaWiki\Logger\LoggerFactory;

/**
 * Builds an Elastica query backed by an elasticsearch QueryString query
 * Has many warts and edge cases that are hardly desirable.
 */
class FullTextQueryStringQueryBuilder implements FullTextQueryBuilder {
	/**
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * @var KeywordFeature[]
	 */
	private $features;

	/**
	 * @var string
	 */
	private $queryStringQueryString = '';

	/**
	 * @var bool
	 */
	private $useTokenCountRouter;

	private NearMatchFieldQueryBuilder $nearMatchFieldQueryBuilder;

	/**
	 * @param SearchConfig $config
	 * @param KeywordFeature[] $features
	 * @param array[] $settings currently ignored
	 */
	public function __construct( SearchConfig $config, array $features, array $settings = [] ) {
		$this->config = $config;
		$this->features = $features;
		$this->useTokenCountRouter = $this->config->getElement(
			'CirrusSearchWikimediaExtraPlugin', 'token_count_router' ) === true;
		$this->nearMatchFieldQueryBuilder = NearMatchFieldQueryBuilder::defaultFromSearchConfig( $config );
	}

	/**
	 * Search articles with provided term.
	 *
	 * @param SearchContext $searchContext
	 * @param string $term term to search
	 * searches that might be better?
	 */
	public function build( SearchContext $searchContext, $term ) {
		$searchContext->addSyntaxUsed( 'full_text' );
		// Transform MediaWiki specific syntax to filters and extra
		// (pre-escaped) query string
		foreach ( $this->features as $feature ) {
			$term = $feature->apply( $searchContext, $term );
		}

		if ( !$searchContext->areResultsPossible() ) {
			return;
		}

		$term = $searchContext->escaper()->escapeQuotes( $term );
		$term = trim( $term );

		// Match quoted phrases including those containing escaped quotes.
		// Those phrases can optionally be followed by ~ then a number (this is
		// the phrase slop). That can optionally be followed by a ~ (this
		// matches stemmed words in phrases). The following all match:
		// "a", "a boat", "a\"boat", "a boat"~, "a boat"~9,
		// "a boat"~9~, -"a boat", -"a boat"~9~
		$slop = $this->config->get( 'CirrusSearchPhraseSlop' );
		$matchQuotesRegex = '(?<![\]])(?<negate>-|!)?(?<main>"((?:[^"]|(?<=\\\)")+)"(?<slop>~\d+)?)(?<fuzzy>~)?';
		$query = self::replacePartsOfQuery(
			$term,
			"/$matchQuotesRegex/",
			function ( $matches ) use ( $searchContext, $slop ) {
				$negate = $matches[ 'negate' ][ 0 ] ? 'NOT ' : '';
				$main = $searchContext->escaper()->fixupQueryStringPart( $matches[ 'main' ][ 0 ] );

				if ( !$negate && !isset( $matches[ 'fuzzy' ] ) && !isset( $matches[ 'slop' ] ) &&
					preg_match( '/^"([^"*]+)[*]"/', $main, $matches )
				) {
					$phraseMatch = new \Elastica\Query\MatchPhrasePrefix();
					$phraseMatch->setFieldQuery( "all.plain", $matches[1] );
					$searchContext->addNonTextQuery( $phraseMatch );
					$searchContext->addSyntaxUsed( 'phrase_match_prefix' );

					$phraseHighlightMatch = new \Elastica\Query\QueryString();
					$phraseHighlightMatch->setQuery( $matches[1] . '*' );
					$phraseHighlightMatch->setFields( [ 'all.plain' ] );
					$searchContext->addNonTextHighlightQuery( $phraseHighlightMatch );

					return [];
				}

				if ( !isset( $matches[ 'fuzzy' ] ) ) {
					if ( !isset( $matches[ 'slop' ] ) ) {
						$main .= '~' . $slop[ 'precise' ];
					}
					// Got to collect phrases that don't use the all field so we can highlight them.
					// The highlighter locks phrases to the fields that specify them.  It doesn't do
					// that with terms.
					return [
						'escaped' => $negate . self::switchSearchToExact( $searchContext, $main, true ),
						'nonAll' => $negate . self::switchSearchToExact( $searchContext, $main, false ),
					];
				}
				return [ 'escaped' => $negate . $main ];
			} );
		// Find prefix matches and force them to only match against the plain analyzed fields.  This
		// prevents prefix matches from getting confused by stemming.  Users really don't expect stemming
		// in prefix queries.
		$maxWildcards = $this->config->get( 'CirrusSearchQueryStringMaxWildcards' );
		$query = self::replaceAllPartsOfQuery( $query, '/\w+\*(?:\w*\*?)*/u',
			function ( $matches ) use ( $searchContext, $maxWildcards ) {
				// hack to detect pathological wildcard
				// relates to T102589 but elastic7 seems to have broken our fix by stopping
				// to propagate the max_determinized_states param to the wildcard queries
				// We might consider fixing this upstream again when switch to opensearch.
				// In the meantine simply count the number of wildcard chars and mimic the previous
				// if we detect such problematic queries
				if ( self::isPathologicalWildcard( $matches[ 0 ][ 0 ], $maxWildcards ) ) {
					$searchContext->addWarning( 'cirrussearch-regex-too-complex-error' );
					$searchContext->setResultsPossible( false );
				}
				$term = $searchContext->escaper()->fixupQueryStringPart( $matches[ 0 ][ 0 ] );
				return [
					'escaped' => self::switchSearchToExactForWildcards( $searchContext, $term ),
					'nonAll' => self::switchSearchToExactForWildcards( $searchContext, $term )
				];
			} );

		$escapedQuery = [];
		$nonAllQuery = [];
		$nearMatchQuery = [];
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'escaped' ] ) ) {
				$escapedQuery[] = $queryPart[ 'escaped' ];
				$nonAllQuery[] = $queryPart['nonAll'] ?? $queryPart['escaped'];
				continue;
			}
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$fixed = $searchContext->escaper()->fixupQueryStringPart( $queryPart[ 'raw' ] );
				$escapedQuery[] = $fixed;
				$nonAllQuery[] = $fixed;
				$nearMatchQuery[] = $queryPart[ 'raw' ];
				continue;
			}
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				'Unknown query part: {queryPart}',
				[ 'queryPart' => serialize( $queryPart ) ]
			);
		}

		// Actual text query
		$this->queryStringQueryString =
			$searchContext->escaper()->fixupWholeQueryString( implode( ' ', $escapedQuery ) );
		$searchContext->setCleanedSearchTerm( $this->queryStringQueryString );

		if ( $this->queryStringQueryString === '' ) {
			$searchContext->addSyntaxUsed( 'filter_only' );
			$searchContext->setHighlightQuery( new MatchAll() );
			return;
		}

		// Note that no escaping is required for near_match's match query.
		$nearMatchQuery = implode( ' ', $nearMatchQuery );
		// If the near match is made only of spaces disable it.
		if ( preg_match( '/^\s+$/', $nearMatchQuery ) === 1 ) {
			$nearMatchQuery = '';
		}

		$queryStringRegex =
			'(' .
				// quoted strings
				$matchQuotesRegex .
			')|(' .
				// patterns that are seen before tokens.
				'(^|\s)[+!-]\S' .
			')|(' .
				// patterns seen after tokens.
				'\S(?<!\\\\)~[0-9]?(\s|$)' .
			')|(' .
				// patterns that are separated from tokens by whitespace
				// on both sides.
				'\s(AND|OR|NOT|&&|\\|\\|)\s' .
			')|(' .
				// patterns that can be at the start of the string
				'^NOT\s' .
			')|(' .
				// patterns that can be inside tokens
				// Note that question mark stripping has already been applied
				'(?<!\\\\)[?*]' .
			')';
		if ( preg_match( "/$queryStringRegex/", $this->queryStringQueryString ) ) {
			$searchContext->addSyntaxUsed( 'query_string' );
		}
		$fields = array_merge(
			self::buildFullTextSearchFields( $searchContext, 1, '.plain', true ),
			self::buildFullTextSearchFields( $searchContext,
				$this->config->get( 'CirrusSearchStemmedWeight' ), '', true ) );

		$searchContext->setMainQuery(
			$this->buildSearchTextQuery(
				$searchContext,
				$fields,
				$this->nearMatchFieldQueryBuilder->buildFromQueryString( $nearMatchQuery ),
				$this->queryStringQueryString
			)
		);

		// The highlighter doesn't know about the weighting from the all fields so we have to send
		// it a query without the all fields.  This swaps one in.
		$nonAllFields = array_merge(
			self::buildFullTextSearchFields( $searchContext, 1, '.plain', false ),
			self::buildFullTextSearchFields( $searchContext,
				$this->config->get( 'CirrusSearchStemmedWeight' ), '', false ) );
		$nonAllQueryString = $searchContext->escaper()
			->fixupWholeQueryString( implode( ' ', $nonAllQuery ) );
		$searchContext->setHighlightQuery(
			$this->buildHighlightQuery( $searchContext, $nonAllFields, $nonAllQueryString, 1 )
		);

		if ( $this->isPhraseRescoreNeeded( $searchContext ) ) {
			$rescoreFields = $fields;

			$searchContext->setPhraseRescoreQuery( $this->buildPhraseRescoreQuery(
						$searchContext,
						$rescoreFields,
						$this->queryStringQueryString,
						$this->config->getElement( 'CirrusSearchPhraseSlop', 'boost' )
					) );
		}
	}

	private function isPathologicalWildcard( string $term, int $maxWildcard ): bool {
		$ret = preg_match_all( "/[*?]+/", $term );
		if ( $ret === false ) {
			// we failed the regex, out of caution fail the query
			return true;
		}
		return $ret > $maxWildcard;
	}

	/**
	 * Attempt to build a degraded query from the query already built into $context. Must be
	 * called *after* self::build().
	 *
	 * @param SearchContext $searchContext
	 * @return bool True if a degraded query was built
	 */
	public function buildDegraded( SearchContext $searchContext ) {
		if ( $this->queryStringQueryString === '' ) {
			return false;
		}

		$fields = array_merge(
			self::buildFullTextSearchFields( $searchContext, 1, '.plain', true ),
			self::buildFullTextSearchFields( $searchContext,
				$this->config->get( 'CirrusSearchStemmedWeight' ), '', true )
		);

		$searchContext->addSyntaxUsed( 'degraded_full_text' );
		$simpleQuery = new \Elastica\Query\Simple( [ 'simple_query_string' => [
			'fields' => $fields,
			'query' => $this->queryStringQueryString,
			'default_operator' => 'AND',
			// Disable all costly operators
			'flags' => 'OR|AND'
		] ] );
		$searchContext->setMainQuery( $simpleQuery );
		$searchContext->setHighlightQuery( $simpleQuery );

		return true;
	}

	/**
	 * Build the primary query used for full text search. This will be a
	 * QueryString query, and optionally a MultiMatch if a $nearMatchQuery
	 * is provided.
	 *
	 * @param SearchContext $searchContext
	 * @param string[] $fields
	 * @param AbstractQuery $nearMatchQuery
	 * @param string $queryString
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildSearchTextQuery(
		SearchContext $searchContext,
		array $fields,
		AbstractQuery $nearMatchQuery,
		$queryString
	) {
		$slop = $this->config->getElement( 'CirrusSearchPhraseSlop', 'default' );
		$queryForMostFields = $this->buildQueryString( $fields, $queryString, $slop );
		$searchContext->addSyntaxUsed( 'full_text_querystring', 5 );
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
	 * Builds the query using the QueryString, this is the default builder
	 * used by cirrus and uses a default AND between clause.
	 * The query 'the query' and the fields all and all.plain will be like
	 * (all:the OR all.plain:the) AND (all:query OR all.plain:query)
	 *
	 * @param string[] $fields
	 * @param string $queryString
	 * @param int $phraseSlop
	 * @return \Elastica\Query\QueryString
	 */
	private function buildQueryString( array $fields, $queryString, $phraseSlop ) {
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( $fields );
		$query->setPhraseSlop( $phraseSlop );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( (bool)$this->config->get( 'CirrusSearchAllowLeadingWildcard' ) );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( $this->getMultiTermRewriteMethod() );
		$states = $this->config->get( 'CirrusSearchQueryStringMaxDeterminizedStates' );
		if ( $states !== null ) {
			$query->setParam( 'max_determinized_states', $states );
		}
		return $query;
	}

	/**
	 * the rewrite method to use for multi term queries
	 * @return string
	 */
	protected function getMultiTermRewriteMethod() {
		return 'top_terms_boost_1024';
	}

	/**
	 * Expand wildcard queries to the all.plain and title.plain fields this is reasonable tradeoff
	 * between perf and precision.
	 *
	 * @param SearchContext $context
	 * @param string $term
	 * @return string
	 */
	private static function switchSearchToExactForWildcards( SearchContext $context, $term ) {
		// Try to limit the expansion of wildcards to all the subfields
		// We still need to add title.plain with a high boost otherwise
		// match in titles be poorly scored (actually it breaks some tests).
		$titleWeight = $context->getConfig()->getElement( 'CirrusSearchWeights', 'title' );
		$fields = [];
		$fields[] = "title.plain:$term^{$titleWeight}";
		$fields[] = "all.plain:$term";
		$exact = implode( ' OR ', $fields );
		return "($exact)";
	}

	/**
	 * Build a QueryString query where all fields being searched are
	 * queried for $term, joined with an OR. This is primarily for the
	 * benefit of the highlighter, the primary search is typically against
	 * the special all field.
	 *
	 * @param SearchContext $context
	 * @param string $term
	 * @param bool $allFieldAllowed
	 * @return string
	 */
	private static function switchSearchToExact( SearchContext $context, $term, $allFieldAllowed ) {
		$exact = implode( ' OR ',
			self::buildFullTextSearchFields( $context, 1, ".plain:$term", $allFieldAllowed ) );
		return "($exact)";
	}

	/**
	 * Build fields searched by full text search.
	 *
	 * @param SearchContext $context
	 * @param float $weight weight to multiply by all fields
	 * @param string $fieldSuffix suffix to add to field names
	 * @param bool $allFieldAllowed can we use the all field?  False for
	 *  collecting phrases for the highlighter.
	 * @return string[] array of fields to query
	 */
	private static function buildFullTextSearchFields(
		SearchContext $context,
		$weight,
		$fieldSuffix,
		$allFieldAllowed
	) {
		$searchWeights = $context->getConfig()->get( 'CirrusSearchWeights' );

		if ( $allFieldAllowed ) {
			return [ "all{$fieldSuffix}^{$weight}" ];
		}

		$fields = [];
		$titleWeight = $weight * $searchWeights[ 'title' ];
		$redirectWeight = $weight * $searchWeights[ 'redirect' ];
		$fields[] = "title{$fieldSuffix}^{$titleWeight}";
		$fields[] = "redirect.title{$fieldSuffix}^{$redirectWeight}";
		$categoryWeight = $weight * $searchWeights[ 'category' ];
		$headingWeight = $weight * $searchWeights[ 'heading' ];
		$openingTextWeight = $weight * $searchWeights[ 'opening_text' ];
		$textWeight = $weight * $searchWeights[ 'text' ];
		$auxiliaryTextWeight = $weight * $searchWeights[ 'auxiliary_text' ];
		$fields[] = "category{$fieldSuffix}^{$categoryWeight}";
		$fields[] = "heading{$fieldSuffix}^{$headingWeight}";
		$fields[] = "opening_text{$fieldSuffix}^{$openingTextWeight}";
		$fields[] = "text{$fieldSuffix}^{$textWeight}";
		$fields[] = "auxiliary_text{$fieldSuffix}^{$auxiliaryTextWeight}";
		$namespaces = $context->getNamespaces();
		if ( !$namespaces || in_array( NS_FILE, $namespaces ) ) {
			$fileTextWeight = $weight * $searchWeights[ 'file_text' ];
			$fields[] = "file_text{$fieldSuffix}^{$fileTextWeight}";
		}
		return $fields;
	}

	/**
	 * Walks through an array of query pieces, as built by
	 * self::replacePartsOfQuery, and replaecs all raw pieces by the result of
	 * self::replacePartsOfQuery when called with the provided regex and
	 * callable. One query piece may turn into one or more query pieces in the
	 * result.
	 *
	 * @param array[] $query The set of query pieces to apply against
	 * @param string $regex Pieces of $queryPart that match this regex will
	 *  be provided to $callable
	 * @param callable $callable A function accepting the $matches from preg_match
	 *  and returning either a raw or escaped query piece.
	 * @return array[] The set of query pieces after applying regex and callable
	 */
	private static function replaceAllPartsOfQuery( array $query, $regex, $callable ) {
		$result = [];
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$result = array_merge( $result,
					self::replacePartsOfQuery( $queryPart[ 'raw' ], $regex, $callable ) );
			} else {
				$result[] = $queryPart;
			}
		}
		return $result;
	}

	/**
	 * Splits a query string into one or more sequential pieces. Each piece
	 * of the query can either be raw (['raw'=>'stuff']), or escaped
	 * (['escaped'=>'stuff']). escaped can also optionally include a nonAll
	 * query (['escaped'=>'stuff','nonAll'=>'stuff']). If nonAll is not set
	 * the escaped query will be used.
	 *
	 * Pieces of $queryPart that do not match the provided $regex are tagged
	 * as 'raw' and may see further parsing. $callable receives pieces of
	 * the string that match the regex and must return either a raw or escaped
	 * query piece.
	 *
	 * @param string $queryPart Raw piece of a user supplied query string
	 * @param string $regex Pieces of $queryPart that match this regex will
	 *  be provided to $callable
	 * @param callable $callable A function accepting the $matches from preg_match
	 *  and returning either a raw or escaped query piece.
	 * @return array[] The sequential set of quer ypieces $queryPart was
	 *  converted into.
	 */
	private static function replacePartsOfQuery( $queryPart, $regex, $callable ) {
		$destination = [];
		$matches = [];
		$offset = 0;
		while ( preg_match( $regex, $queryPart, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$startOffset = $matches[0][1];
			if ( $startOffset > $offset ) {
				$destination[] = [
					'raw' => substr( $queryPart, $offset, $startOffset - $offset )
				];
			}

			$callableResult = $callable( $matches );
			if ( $callableResult ) {
				$destination[] = $callableResult;
			}

			$offset = $startOffset + strlen( $matches[0][0] );
		}

		if ( $offset < strlen( $queryPart ) ) {
			$destination[] = [
				'raw' => substr( $queryPart, $offset ),
			];
		}

		return $destination;
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
		return $this->buildQueryString( $fields, $queryText, $slop );
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
		return $this->maybeWrapWithTokenCountRouter(
			$queryText,
			$this->buildQueryString( $fields, '"' . $queryText . '"', $slop )
		);
	}

	/**
	 * Determines if a phrase rescore is needed
	 * @param SearchContext $searchContext
	 * @return bool true if we can a phrase rescore
	 */
	protected function isPhraseRescoreNeeded( SearchContext $searchContext ) {
		// Only do a phrase match rescore if the query doesn't include
		// any quotes and has a space or the token count router is
		// active.
		// Queries without spaces are either single term or have a
		// phrase query generated.
		// Queries with the quote already contain a phrase query and we
		// can't build phrase queries out of phrase queries at this
		// point.
		if ( !$searchContext->isSpecialKeywordUsed() &&
			strpos( $this->queryStringQueryString, '"' ) === false &&
			( $this->useTokenCountRouter || strpos( $this->queryStringQueryString, ' ' ) !== false )
		) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $queryText
	 * @param AbstractQuery $query
	 * @return AbstractQuery
	 */
	protected function maybeWrapWithTokenCountRouter( $queryText, \Elastica\Query\AbstractQuery $query ) {
		if ( $this->useTokenCountRouter ) {
			$tokCount = new TokenCountRouter(
				// text
				$queryText,
				// fallack
				new \Elastica\Query\MatchNone(),
				// field
				'text'
			);
			$maxTokens = $this->config->get( 'CirrusSearchMaxPhraseTokens' );
			if ( $maxTokens ) {
				$tokCount->addCondition(
					TokenCountRouter::GT,
					$maxTokens,
					new \Elastica\Query\MatchNone()
				);
			}
			$tokCount->addCondition(
				TokenCountRouter::GT,
				1,
				$query
			);
			return $tokCount;
		}
		return $query;
	}
}
