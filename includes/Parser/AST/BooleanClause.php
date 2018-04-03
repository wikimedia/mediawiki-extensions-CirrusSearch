<?php


namespace CirrusSearch\Parser\AST;

use Wikimedia\Assert\Assert;

/**
 * A boolean clause
 */
class BooleanClause {
	const MUST = 'MUST';
	const SHOULD = 'SHOULD';
	const MUST_NOT = 'MUST_NOT';

	/**
	 * @var ParsedNode
	 */
	private $node;

	/**
	 * Specifies how this clause is to occur in matching documents
	 * @var int
	 */
	private $occur;

	/**
	 * @var bool true if the node is explicitly connected
	 */
	private $explicit = false;

	/**
	 * BooleanClause constructor.
	 * @param ParsedNode $node
	 * @param string $occur Specifies how this clause is to occur in matching documents.
	 * @param bool $explicit whether or not this node is explicitly connected
	 * acceptable values are BooleanClause::MUST, BooleanClause::MUST_NOT and BooleanClause::SHOULD
	 */
	public function __construct( ParsedNode $node, $occur, $explicit ) {
		$this->node = $node;
		Assert::parameter( $occur === self::MUST || $occur === self::SHOULD || $occur === self::MUST_NOT,
			'$occur', 'occur param must be either: MUST, SHOULD or MUST_NOT' );
		$this->occur = $occur;
		$this->explicit = $explicit;
	}

	/**
	 * @return ParsedNode
	 */
	public function getNode() {
		return $this->node;
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
}
