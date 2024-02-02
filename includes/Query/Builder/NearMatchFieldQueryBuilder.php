<?php

namespace CirrusSearch\Query\Builder;

use CirrusSearch\Parser\AST\EmptyQueryNode;
use CirrusSearch\Parser\AST\FuzzyNode;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\AST\ParsedNode;
use CirrusSearch\Parser\AST\ParsedQuery;
use CirrusSearch\Parser\AST\PhrasePrefixNode;
use CirrusSearch\Parser\AST\PhraseQueryNode;
use CirrusSearch\Parser\AST\PrefixNode;
use CirrusSearch\Parser\AST\Visitor\LeafVisitor;
use CirrusSearch\Parser\AST\WildcardNode;
use CirrusSearch\Parser\AST\WordsQueryNode;
use CirrusSearch\Query\InTitleFeature;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MatchNone;
use Elastica\Query\MultiMatch;
use Wikimedia\Assert\Assert;

/**
 * ParseQuery visitor that attempts to extract a form that resembles to the near match query.
 * This implementation tries to mimic the strategy of the old query parser that works by removing
 * keywords. It might make sense in the future to reconsider this approach and see if there are
 * better strategies to apply with the help of the ParsedQuery.
 */
class NearMatchFieldQueryBuilder {
	public const ALL_NEAR_MATCH = "all_near_match";
	public const ALL_NEAR_MATCH_ACCENT_FOLDED = self::ALL_NEAR_MATCH . ".asciifolding";
	private array $profile;

	public static function defaultFromSearchConfig( SearchConfig $config ): self {
		return self::defaultFromWeight( $config->get( 'CirrusSearchNearMatchWeight' ) ?: 2 );
	}

	public static function defaultFromWeight( float $weight ): self {
		return new self(
			[ "fields" => [
				[ "name" => self::ALL_NEAR_MATCH, "weight" => round( $weight, 3 ) ],
				[ "name" => self::ALL_NEAR_MATCH_ACCENT_FOLDED, "weight" => round( $weight * 0.75, 3 ) ]
			] ]
		);
	}

	public function __construct( array $profile ) {
		$this->profile = $profile;
	}

	public function buildFromParsedQuery( ParsedQuery $query ): AbstractQuery {
		$visitor = new class( $query ) extends LeafVisitor {
			public string $nearMatch;

			public function __construct( ParsedQuery $query ) {
				parent::__construct();
				$this->nearMatch = $query->getQuery();
				$nsHeader = $query->getNamespaceHeader();
				if ( $nsHeader != null ) {
					$this->blank( $nsHeader );
				}
			}

			/**
			 * Blank the portion of the search query located at the same location as the $node.
			 * A custom replacement can be passed but must not have a length greater than this location.
			 * @param ParsedNode $node the node holding the location of the query string we want to blank
			 * @param string $replacement optional replacement string to use
			 */
			private function blank( ParsedNode $node, string $replacement = "" ): void {
				$l = $node->getEndOffset() - $node->getStartOffset();
				Assert::parameter( strlen( $replacement ) < $l, '$replacement',
					'must be shorter than the replaced ParsedNode' );
				$this->nearMatch = substr_replace(
					$this->nearMatch,
					str_pad( $replacement, $l, " " ),
					$node->getStartOffset(), $l
				);
			}

			/** {@inheritdoc} */
			public function visitWordsQueryNode( WordsQueryNode $node ) {
			}

			/** {@inheritdoc} */
			public function visitPhraseQueryNode( PhraseQueryNode $node ) {
			}

			/** {@inheritdoc} */
			public function visitPhrasePrefixNode( PhrasePrefixNode $node ) {
			}

			/** {@inheritdoc} */
			public function visitFuzzyNode( FuzzyNode $node ) {
			}

			/** {@inheritdoc} */
			public function visitPrefixNode( PrefixNode $node ) {
			}

			/** {@inheritdoc} */
			public function visitWildcardNode( WildcardNode $node ) {
			}

			/** {@inheritdoc} */
			public function visitEmptyQueryNode( EmptyQueryNode $node ) {
			}

			/** {@inheritdoc} */
			public function visitKeywordFeatureNode( KeywordFeatureNode $node ) {
				if ( !$this->negated() && ( $node->getKeyword() instanceof InTitleFeature ) && $node->getParsedValue() == [] ) {
					// TODO: generalize this InTitleFeature behavior
					// We want to keep the text of the intitle keyword on if:
					// - it's not negated
					// - it's not a regular expression (using $node->getParsedValue() == [] )
					$this->blank( $node, $node->getQuotedValue() );
				} else {
					$clause = $this->getCurrentBooleanClause();
					// painful attempt to keep a weird edge-case of the old query parser that does not
					// support negating keyword clause with an explicit NOT.
					// Might be interesting to re-consider the usefulness of such edge-case
					// "NOT keyword:value" becomes "NOT"
					// but "-keyword:value" becomes ""
					// we detect the use of NOT or - using BooleanClause::isExplicit
					$negatedNode = $clause != null ? $clause->getNegatedNode() : null;
					if ( $negatedNode !== null && !$clause->isExplicit() ) {
						// the negated node should have the proper offsets to blank the "-"
						$this->blank( $negatedNode );
					} else {
						$this->blank( $node );
					}
				}
			}
		};
		$query->getRoot()->accept( $visitor );
		$queryString = trim( preg_replace( '/\s{2,}/', ' ', $visitor->nearMatch ) );

		return $this->buildFromQueryString( $queryString );
	}

	public function buildFromQueryString( string $query ): AbstractQuery {
		if ( preg_match( '/^\s*$/', $query ) === 1 ) {
			return new MatchNone();
		}
		$allQuery = new MultiMatch();
		$allQuery->setQuery( $query );
		$allQuery->setFields(
			array_map(
				static function ( array $fieldDef ): string {
					return $fieldDef["name"] . "^" . $fieldDef["weight"];
				},
				$this->profile["fields"]
			)
		);
		return $allQuery;
	}

}
