<?php declare( strict_types=1 );

namespace CirrusSearch\Parser\AST\Visitor;

use CirrusSearch\Parser\AST\BooleanClause;
use CirrusSearch\Parser\AST\NamespaceHeaderNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParsedBooleanNode;
use Wikimedia\Assert\Assert;

/**
 * Visit leaves only
 */
abstract class LeafVisitor implements Visitor {
	/**
	 * @var string[]
	 */
	private array $excludeOccurs;

	/**
	 * @var bool true when this branch is "negated".
	 */
	private bool $inNegation = false;
	private ?BooleanClause $currentClause = null;

	/**
	 * @param string[] $excludeOccurs
	 */
	public function __construct( array $excludeOccurs = [] ) {
		array_walk( $excludeOccurs, static fn ( $x ) => BooleanClause::validateOccur( $x ) );
		$this->excludeOccurs = $excludeOccurs;
	}

	final public function visitParsedBooleanNode( ParsedBooleanNode $node ): void {
		foreach ( $node->getClauses() as $clause ) {
			$clause->accept( $this );
		}
	}

	final public function visitNegatedNode( NegatedNode $node ): void {
		/** @phan-suppress-next-line PhanImpossibleCondition I agree, this is impossible. */
		Assert::invariant( false, 'NegatedNode should be optimized at parse time' );
	}

	final public function visitNamespaceHeader( NamespaceHeaderNode $node ): void {
		/** @phan-suppress-next-line PhanImpossibleCondition I agree, this is impossible. */
		Assert::invariant( false, 'Not yet part of the AST, should not be visited.' );
	}

	final public function visitBooleanClause( BooleanClause $clause ): void {
		if ( in_array( $clause->getOccur(), $this->excludeOccurs ) ) {
			return;
		}

		$oldNegated = $this->inNegation;
		$oldClause = $this->currentClause;
		if ( $clause->getOccur() === BooleanClause::MUST_NOT ) {
			$this->inNegation = !$this->inNegation;
		}
		$this->currentClause = $clause;

		$clause->getNode()->accept( $this );
		$this->inNegation = $oldNegated;
		$this->currentClause = $oldClause;
	}

	/**
	 * @return bool true if this node is in a negation
	 */
	final public function negated(): bool {
		return $this->inNegation;
	}

	/**
	 * @return BooleanClause|null the boolean clause the visited node is in or null if top-level
	 */
	final public function getCurrentBooleanClause(): ?BooleanClause {
		return $this->currentClause;
	}
}
