<?php

namespace CirrusSearch\Query;

use CirrusSearch\Extra\Query\TermFreq;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\WeightedTagsHooks;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MatchQuery;
use MediaWiki\Message\Message;

/**
 * Filters the result set based on the existing article recommendation.
 * Currently we handle link and image recommendations.
 *
 * Examples:
 *   hasrecommendation:image
 *   hasrecommendation:link|image
 */
class HasRecommendationFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	public const WARN_MESSAGE_INVALID_THRESHOLD = "cirrussearch-invalid-keyword-threshold";

	/**
	 * Limit filtering to 5 recommendation types. Arbitrarily chosen, but should be more
	 * than enough and some sort of limit has to be enforced.
	 */
	public const QUERY_LIMIT = 5;
	private int $maxScore;

	public function __construct( int $maxScore ) {
		$this->maxScore = $maxScore;
	}

	/** @inheritDoc */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$parsedValue = $this->parseValue( $key, $value, $quotedValue, '', '', $context );
		return [ $this->doGetFilterQuery( $parsedValue ), false ];
	}

	/** @inheritDoc */
	protected function getKeywords() {
		return [ 'hasrecommendation' ];
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param string $valueDelimiter
	 * @param string $suffix
	 * @param WarningCollector $warningCollector
	 * @return array|false|null
	 */
	public function parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix,
								WarningCollector $warningCollector ) {
		$recFlags = explode( "|", $value );
		if ( count( $recFlags ) > self::QUERY_LIMIT ) {
			$warningCollector->addWarning(
				'cirrussearch-feature-too-many-conditions',
				$key,
				self::QUERY_LIMIT
			);
			$recFlags = array_slice( $recFlags, 0, self::QUERY_LIMIT );
		}
		$recFlags = array_map(
			static function ( string $k ) use ( $warningCollector ): array {
				$matches = [];
				preg_match( '/(?<tag>[^<>=]+)((?<comp>>=|<=|[<>=])(?<thresh>.*$))?/', $k, $matches );
				$comp = null;
				$threshold = null;
				$tag = $k;
				if ( isset( $matches['comp'] ) ) {
					$invalidThreshold = false;
					$tag = $matches['tag'];
					if ( !is_numeric( $matches['thresh'] ) ) {
						$invalidThreshold = true;
					} else {
						$t = floatval( $matches['thresh'] );
						if ( $t <= 1.0 && $t >= 0.0 ) {
							$threshold = $t;
							$comp = $matches['comp'];
						} else {
							$invalidThreshold = true;
						}
					}
					if ( $invalidThreshold ) {
						$warningCollector->addWarning( self::WARN_MESSAGE_INVALID_THRESHOLD,
							Message::plaintextParam( $matches['thresh'] ) );
					}
				}
				return [
					'flag' => $tag,
					'comp' => $comp,
					'threshold' => $threshold,
				];
			},
			$recFlags
		);
		return [ 'recommendationflags' => $recFlags ];
	}

	/**
	 * @param array[] $parsedValue
	 * @return AbstractQuery|null
	 */
	private function doGetFilterQuery( array $parsedValue ): ?AbstractQuery {
		$queries = [];
		foreach ( $parsedValue['recommendationflags'] as $recFlag ) {
			$tagValue = "recommendation." . $recFlag['flag'] . '/exists';
			if ( $recFlag['comp'] ) {
				$queries[] = new TermFreq(
					WeightedTagsHooks::FIELD_NAME,
					$tagValue,
					$recFlag['comp'],
					(int)( $recFlag['threshold'] * $this->maxScore )
				);
			} else {
				$queries[] = ( new MatchQuery() )->setFieldQuery( WeightedTagsHooks::FIELD_NAME, $tagValue );
			}
		}
		return Filters::booleanOr( $queries, false );
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ): ?AbstractQuery {
		return $this->doGetFilterQuery( $node->getParsedValue() );
	}

}
