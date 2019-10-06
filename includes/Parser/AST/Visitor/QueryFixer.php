<?php

namespace CirrusSearch\Parser\AST\Visitor;

use CirrusSearch\Parser\AST\BooleanClause;
use CirrusSearch\Parser\AST\EmptyQueryNode;
use CirrusSearch\Parser\AST\FuzzyNode;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\AST\NamespaceHeaderNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParsedBooleanNode;
use CirrusSearch\Parser\AST\ParsedNode;
use CirrusSearch\Parser\AST\ParsedQuery;
use CirrusSearch\Parser\AST\PhrasePrefixNode;
use CirrusSearch\Parser\AST\PhraseQueryNode;
use CirrusSearch\Parser\AST\PrefixNode;
use CirrusSearch\Parser\AST\WildcardNode;
use CirrusSearch\Parser\AST\WordsQueryNode;
use Wikimedia\Assert\Assert;

/**
 * Inspect a query and determine what parts of it can be sent to a typo correction mechanism and
 * provide a method to fix the query once the corrected substring is known.
 */
class QueryFixer implements Visitor {
	/**
	 * @var \SplObjectStorage
	 */
	private static $cache;

	/**
	 * @var ParsedQuery
	 */
	private $parsedQuery;

	/**
	 * @var bool
	 */
	private $visited = false;

	/**
	 * @var ParsedNode
	 */
	private $node;

	/**
	 * @var bool
	 */
	private $hasQMarkInWildcard = false;

	/**
	 * @var int
	 */
	private $currentSize = 0;

	/**
	 * @var bool true when this branch is "negated".
	 */
	private $inNegation;

	/**
	 * @var bool
	 */
	private $isComplex = false;

	/**
	 * @param ParsedQuery $query
	 */
	public function __construct( ParsedQuery $query ) {
		$this->parsedQuery = $query;
	}

	/**
	 * @param ParsedQuery $query
	 * @return QueryFixer|object|null
	 */
	public static function build( ParsedQuery $query ) {
		if ( self::$cache === null || count( self::$cache ) > 100 ) {
			// Build the cache for the first time or drop it for a new empty one just in case this class
			// is used from a maint script that treats/parses millions of queries
			self::$cache = new \SplObjectStorage();
		}

		$fixer = self::$cache[$query] ?? null;
		if ( $fixer === null ) {
			$fixer = new self( $query );
			self::$cache[$query] = $fixer;
		}
		return $fixer;
	}

	/**
	 * Get the longest phrase that is subject to typo correction.
	 * It's generally a set of consecutive words.
	 *
	 * @return string|null
	 */
	public function getFixablePart() {
		if ( !$this->visited ) {
			$this->visited = true;
			$this->parsedQuery->getRoot()->accept( $this );
		}

		if ( $this->isComplex ) {
			$this->node = null;
		}

		if ( $this->hasQMarkInWildcard && $this->parsedQuery->hasCleanup( ParsedQuery::CLEANUP_QMARK_STRIPPING ) ) {
			// We may not be able to reconstruct this kind of queries properly
			// If a question mark is legimetely removed we agree that it's OK to present the user
			// with its original query minus the question marks.
			// But if the user explicitely escaped the question mark so that it generates a valid
			// wildcard query we don't attempt to re-escape the resulting query.
			$this->node = null;
		}

		if ( $this->node === null ) {
			return null;
		}

		if ( $this->node instanceof KeywordFeatureNode ) {
			return $this->node->getValue();
		} elseif ( $this->node instanceof WordsQueryNode ) {
			return $this->node->getWords();
		} else {
			Assert::invariant( false, "Unsupported node type " . get_class( $this->node ) );
			return null;
		}
	}

	/**
	 * Return a fixed query using $fixedQuery
	 * @param string $fixedQuery
	 * @param bool $escapeBoundaries escape boundaries using htmlspecialchars when true, $fixedQuery
	 * is supposed to be already escaped.
	 * @return string|null
	 */
	public function fix( $fixedQuery, $escapeBoundaries = false ) {
		Assert::precondition( $this->visited, "getFixablePart must be called before trying to fix the query" );
		if ( $this->node === null ) {
			return null;
		}
		$fixedQuery = preg_replace( '/([~?*"\\\\])/', '\\\\$1', $fixedQuery );
		$res = "";
		if ( $this->parsedQuery->hasCleanup( ParsedQuery::TILDE_HEADER ) ) {
			$res .= "~";
		}
		$res .= substr( $this->parsedQuery->getQuery(), 0, $this->node->getStartOffset() );
		if ( $this->node instanceof KeywordFeatureNode ) {
			$res .= $this->node->getKey() . ':';
		}

		if ( $escapeBoundaries ) {
			$safeRes = htmlspecialchars( $res );
		} else {
			$safeRes = $res;
		}
		$safeRes .= $fixedQuery;

		if ( $escapeBoundaries ) {
			$suffix = htmlspecialchars(
				substr( $this->parsedQuery->getQuery(), $this->node->getEndOffset() )
			);
		} else {
			$suffix = substr( $this->parsedQuery->getQuery(), $this->node->getEndOffset() );
		}

		$safeRes .= $suffix;
		return $safeRes;
	}

	/**
	 * @param WordsQueryNode $node
	 */
	public function visitWordsQueryNode( WordsQueryNode $node ) {
		if ( $this->inNegation ) {
			return;
		}
		$siz = mb_strlen( $node->getWords() );
		if ( $siz > $this->currentSize ) {
			if ( !$this->acceptableString( $node->getWords() ) ) {
				return;
			}
			$this->node = $node;
			$this->currentSize = $siz;
		}
	}

	/**
	 * Determine if this substring of the query is suitable for being fixed.
	 * Excludes string with chars that may require escaping (*, ?, " and \)
	 * @param $str
	 * @return bool
	 */
	private function acceptableString( $str ) {
		// We ignore word parts that we me have to escape
		// when presenting the query back to the user
		return preg_match( '/[*?"\\\\]/', $str ) !== 1;
	}

	/**
	 * @param PhraseQueryNode $node
	 */
	public function visitPhraseQueryNode( PhraseQueryNode $node ) {
		$this->isComplex = true;
	}

	/**
	 * @param PhrasePrefixNode $node
	 */
	public function visitPhrasePrefixNode( PhrasePrefixNode $node ) {
		$this->isComplex = true;
	}

	/**
	 * @param FuzzyNode $node
	 */
	public function visitFuzzyNode( FuzzyNode $node ) {
		$this->isComplex = true;
	}

	/**
	 * @param PrefixNode $node
	 */
	public function visitPrefixNode( PrefixNode $node ) {
		$this->isComplex = true;
	}

	/**
	 * @param WildcardNode $node
	 */
	public function visitWildcardNode( WildcardNode $node ) {
		if ( strpos( $node->getWildcardQuery(), '?' ) !== -1 ) {
			$this->hasQMarkInWildcard = true;
		}
		$this->isComplex = true;
	}

	/**
	 * @param EmptyQueryNode $node
	 */
	public function visitEmptyQueryNode( EmptyQueryNode $node ) {
	}

	/**
	 * @param KeywordFeatureNode $node
	 */
	public function visitKeywordFeatureNode( KeywordFeatureNode $node ) {
		// FIXME: fixing intitle is perhaps a side effect of the original cirrus query parser
		if ( !$this->inNegation && $node->getKey() === 'intitle' && $node->getDelimiter() === '' ) {
			$siz = strlen( $node->getValue() );
			if ( $siz > $this->currentSize && $this->acceptableString( $node->getValue() ) ) {
				$this->node = $node;
				$this->currentSize = $siz;
			}
		}
	}

	/**
	 * @param ParsedBooleanNode $node
	 */
	public function visitParsedBooleanNode( ParsedBooleanNode $node ) {
		foreach ( $node->getClauses() as $clause ) {
			$this->visitBooleanClause( $clause );
		}
	}

	/**
	 * @param BooleanClause $clause
	 */
	public function visitBooleanClause( BooleanClause $clause ) {
		if ( $clause->isExplicit() ) {
			$this->isComplex = true;
		}
		$oldNegated = $this->inNegation;
		$node = $clause->getNode();
		if ( $node instanceof KeywordFeatureNode && $node->getKey() === 'intitle' && $node->getDelimiter() === '' ) {
			// Inhibits the fixer when it sees an un-acceptable value inside a keyword (legacy browsertest_176)
			$this->isComplex = $this->isComplex || !$this->acceptableString( $node->getValue() );
		}
		if ( $clause->getOccur() === BooleanClause::MUST_NOT ) {
			if ( !$node instanceof KeywordFeatureNode ) {
				// FIXME: (legacy) only negated keywords were accepted
				$this->isComplex = true;
			}
			$this->inNegation = !$this->inNegation;
		}

		$clause->getNode()->accept( $this );
		$this->inNegation = $oldNegated;
	}

	/**
	 * @param NegatedNode $node
	 */
	final public function visitNegatedNode( NegatedNode $node ) {
		Assert::invariant( false, 'NegatedNode should be optimized at parse time' );
	}

	/**
	 * @param NamespaceHeaderNode $node
	 */
	final public function visitNamespaceHeader( NamespaceHeaderNode $node ) {
		Assert::invariant( false, 'Not yet part of the AST, should not be visited.' );
	}
}
