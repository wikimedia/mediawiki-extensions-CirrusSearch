<?php

namespace CirrusSearch\Parser\AST\Visitor;

/**
 * "Visitable" node from the AST
 */
interface Visitable {
	/**
	 * @param Visitor $visitor
	 */
	function accept( Visitor $visitor );
}
