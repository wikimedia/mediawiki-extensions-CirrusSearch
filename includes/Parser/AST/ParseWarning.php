<?php

namespace CirrusSearch\Parser\AST;

/**
 * A warning that occured during the parse process.
 */
class ParseWarning {
	/**
	 * @var string
	 */
	private $message;

	/**
	 * @var string[]
	 */
	private $expectedTokens;

	/**
	 * @var string
	 */
	private $actualToken;
	/**
	 * @var int
	 */
	private $start;

	/**
	 * ParseWarning constructor.
	 * @param string $message
	 * @param int $start
	 * @param string[] $expectedTokens
	 * @param string|null $actualToken
	 */
	public function __construct( $message, $start, array $expectedTokens = [], $actualToken = null ) {
		$this->message = $message;
		$this->expectedTokens = $expectedTokens;
		$this->actualToken = $actualToken;
		$this->start = $start;
	}

	/**
	 * message code
	 * @return string
	 */
	public function getMessage() {
		return $this->message;
	}

	/**
	 * Token types that were expected
	 * The type names are parser dependent but should provide meaningful
	 * hints to the user
	 * @return string[]
	 */
	public function getExpectedTokens() {
		return $this->expectedTokens;
	}

	/**
	 * Token found
	 * @return string
	 */
	public function getActualToken() {
		return $this->actualToken;
	}

	/**
	 * Offset of the error.
	 * NOTE: Offset in byte (mb_strcut if you want to provide feedback and print the error in context)
	 * @return int
	 */
	public function getStart() {
		return $this->start;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		$ar = [
			'msg' => $this->message,
			'start' => $this->getStart(),
		];
		if ( count( $this->expectedTokens ) > 0 ) {
			$ar['expected'] = $this->expectedTokens;
		}
		if ( $this->actualToken !== null ) {
			$ar['actual'] = $this->actualToken;
		}
		return $ar;
	}
}
