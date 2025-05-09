<?php declare( strict_types=1 );

namespace CirrusSearch\Parser\AST;

use CirrusSearch\Parser\AST\Visitor\Visitor;

/**
 * A simple word prefix query
 */
class PrefixNode extends ParsedNode {

	private string $prefix;

	public function __construct( int $startOffset, int $endOffset, string $prefix ) {
		parent::__construct( $startOffset, $endOffset );
		$this->prefix = $prefix;
	}

	public function getPrefix(): string {
		return $this->prefix;
	}

	public function toArray(): array {
		return [ 'prefix' => array_merge( parent::baseParams(), [ 'prefix' => $this->prefix ] ) ];
	}

	public function accept( Visitor $visitor ): void {
		$visitor->visitPrefixNode( $this );
	}

}
