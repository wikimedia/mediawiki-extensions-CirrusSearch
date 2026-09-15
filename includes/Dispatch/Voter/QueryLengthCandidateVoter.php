<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * Proposes a route for queries of at least a given length.
 *
 * Length stands in for the kind of query a route like semantic retrieval is expected to
 * help with: a question in natural language rather than two or three keywords. The count
 * excludes the namespace header, because the header is routing metadata and not part of
 * what the user asked for.
 *
 * Tokenizing on whitespace in PHP is deliberately crude. It gives the route an answer without
 * a request to the search backend, which the dispatcher can't afford before it has chosen a
 * route. The cost is that it counts a run of CJK text as one token, so wikis in those languages
 * must not use this voter.
 *
 * @license GPL-2.0-or-later
 */
class QueryLengthCandidateVoter implements RouteVoter {
	/** Profile param holding the minimum token count outright. */
	public const PARAM_THRESHOLD = 'threshold';

	/**
	 * Profile param naming the $wgCirrusSearch* setting to read the minimum token count
	 * from, for a route whose threshold is a knob the wiki turns. Ignored when
	 * PARAM_THRESHOLD is given.
	 */
	public const PARAM_THRESHOLD_CONFIG = 'threshold_config';

	/** @var int Minimum number of tokens that makes a query a candidate. */
	private int $threshold;

	public function __construct( int $threshold ) {
		$this->threshold = $threshold;
	}

	/** @inheritDoc */
	public static function build( SearchConfig $config, array $params ): ?self {
		$threshold = $params[self::PARAM_THRESHOLD] ?? null;
		if ( $threshold === null && isset( $params[self::PARAM_THRESHOLD_CONFIG] ) ) {
			$threshold = $config->get( $params[self::PARAM_THRESHOLD_CONFIG] );
		}
		if ( $threshold === null ) {
			// No threshold, so the voter has nothing to say about any query. This is the
			// normal state of a wiki that has not turned the knob on yet.
			return null;
		}
		return new self( (int)$threshold );
	}

	public function vote( SearchQuery $query ): RouteVote {
		$tokens = self::countTokens( $query->getParsedQuery()->getQueryWithoutNsHeader() );
		return $tokens >= $this->threshold ? RouteVote::Candidate : RouteVote::Abstain;
	}

	private static function countTokens( string $query ): int {
		$query = trim( $query );
		if ( $query === '' ) {
			return 0;
		}
		return count( preg_split( '/\s+/', $query ) );
	}
}
