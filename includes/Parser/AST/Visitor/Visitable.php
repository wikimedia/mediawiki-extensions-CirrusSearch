<?php

namespace CirrusSearch\Parser\AST\Visitor;

/**
 * "Visitable" node from the AST
 */
interface Visitable {
	/**
	 * @param Visitor $visitor
	 * @return void
	 */
	public function accept( Visitor $visitor );
}
