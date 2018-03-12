<?php

namespace CirrusSearch\Parser\AST;

/**
 * Wildcard query
 */
class WildcardNode extends ParsedNode {

	/**
	 * @var string
	 */
	private $wildcardQuery;

	/**
	 *
	 * WildcardNode constructor.
	 * @param int $startOffset
	 * @param int $endOffset
	 * @param string $wildcard the wildcard query (should remain as written by the user)
	 */
	public function __construct( $startOffset, $endOffset, $wildcard ) {
		parent::__construct( $startOffset, $endOffset );
		$this->wildcardQuery = $wildcard;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return [ 'wildcard' => array_merge( parent::baseParams(),
			[ 'wildcardquery' => $this->wildcardQuery ] ) ];
	}

	/**
	 * The wildcard query.
	 * Beware that it may contain potentially slow queries:
	 * - leading wildcards
	 * - non negligible number of wildcards
	 * @return string
	 */
	public function getWildcardQuery() {
		return $this->wildcardQuery;
	}
}
