<?php

namespace CirrusSearch\Parser\AST;

/**
 * Empty query node (we could not parse anything useful)
 */
class EmptyQueryNode extends ParsedNode {

	/**
	 * @return array
	 */
	public function toArray() {
		return [ 'empty' => parent::baseParams() ];
	}
}
