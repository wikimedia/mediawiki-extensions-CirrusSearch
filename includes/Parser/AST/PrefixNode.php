<?php

namespace CirrusSearch\Parser\AST;

/**
 * A simple word prefix query
 */
class PrefixNode extends ParsedNode {

	/**
	 * @var string
	 */
	private $prefix;

	/**
	 * PrefixNode constructor.
	 * @param int $startOffset
	 * @param int $endOffset
	 * @param string $prefix
	 */
	public function __construct( $startOffset, $endOffset, $prefix ) {
		parent::__construct( $startOffset, $endOffset );
		$this->prefix = $prefix;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return [ 'prefix' => [ array_merge( parent::baseParams(), [ 'prefix' => $this->prefix ] ) ] ];
	}
}
