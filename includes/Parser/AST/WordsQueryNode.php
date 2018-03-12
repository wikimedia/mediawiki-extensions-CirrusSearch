<?php


namespace CirrusSearch\Parser\AST;

/**
 * Simple query node made of words.
 */
class WordsQueryNode extends ParsedNode {

	/**
	 * @var string
	 */
	private $words;

	/**
	 * WordsQueryNode constructor.
	 * @param int $startOffset
	 * @param int $endOffset
	 * @param string $words
	 */
	public function __construct( $startOffset, $endOffset, $words ) {
		parent::__construct( $startOffset, $endOffset );
		$this->words = $words;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return [
			'words' => array_merge( parent::baseParams(), [
				'words' => $this->words
			] )
		];
	}

	/**
	 * @return string
	 */
	public function getWords() {
		return $this->words;
	}
}
