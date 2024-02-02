<?php

namespace CirrusSearch\Parser\AST;

use CirrusSearch\Parser\AST\Visitor\Visitable;
use CirrusSearch\Parser\AST\Visitor\Visitor;
use Wikimedia\Assert\Assert;

/**
 * A boolean clause
 */
class BooleanClause implements Visitable {
	public const MUST = 'MUST';
	public const SHOULD = 'SHOULD';
	public const MUST_NOT = 'MUST_NOT';

	/**
	 * @var ParsedNode
	 */
	private $node;

	/**
	 * Specifies how this clause is to occur in matching documents
	 * @var string
	 */
	private $occur;

	/**
	 * @var bool true if the node is explicitly connected
	 */
	private $explicit;

	private ?NegatedNode $negatedNode;

	/**
	 * @param ParsedNode $node
	 * @param string $occur Specifies how this clause is to occur in matching documents.
	 * @param bool $explicit whether or not this node is explicitly connected
	 * @param NegatedNode|null $negatedNode when in a MUST_NOT, remember how this clause was negated
	 * acceptable values are BooleanClause::MUST, BooleanClause::MUST_NOT and BooleanClause::SHOULD
	 */
	public function __construct( ParsedNode $node, $occur, $explicit, ?NegatedNode $negatedNode = null ) {
		$this->node = $node;
		$this->occur = $occur;
		self::validateOccur( $occur );
		Assert::parameter( ( $occur === self::MUST_NOT ) === ( $negatedNode !== null ), '$negatedNode',
			'A NegatedNode must be provided only if occur is MUST_NOT' );
		$this->explicit = $explicit;
		$this->negatedNode = $negatedNode;
	}

	/**
	 * @return ParsedNode
	 */
	public function getNode() {
		return $this->node;
	}

	/**
	 * Check if $occur is valid
	 * @param string $occur
	 */
	public static function validateOccur( $occur ) {
		Assert::parameter( $occur === self::MUST || $occur === self::SHOULD || $occur === self::MUST_NOT,
			'$occur', 'must be either: MUST, SHOULD or MUST_NOT' );
	}

	/**
	 * Specifies how this clause is to occur in matching documents
	 * @return string
	 */
	public function getOccur() {
		return $this->occur;
	}

	/**
	 * @return bool
	 */
	public function isExplicit() {
		return $this->explicit;
	}

	/**
	 * @param Visitor $visitor
	 */
	public function accept( Visitor $visitor ) {
		$visitor->visitBooleanClause( $this );
	}

	/**
	 *
	 * @return NegatedNode|null
	 */
	public function getNegatedNode(): ?NegatedNode {
		return $this->negatedNode;
	}
}
