<?php


namespace CirrusSearch\Parser\AST;

/**
 * A phrase prefix.
 */
class PhrasePrefixNode extends ParsedNode {

	/**
	 * @var string
	 */
	private $phrase;

	/**
	 * @param int $startOffset
	 * @param int $endOffset
	 * @param string $phrase
	 */
	public function __construct( $startOffset, $endOffset, $phrase ) {
		parent::__construct( $startOffset, $endOffset );
		$this->phrase = $phrase;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return [
			"phrase_prefix" => array_merge( parent::baseParams(), [
				'phrase' => $this->phrase
			] )
		];
	}

	/**
	 * @return string
	 */
	public function getPhrase() {
		return $this->phrase;
	}
}
