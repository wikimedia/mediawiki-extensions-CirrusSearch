<?php

namespace CirrusSearch\Parser\AST;

use CirrusSearch\Query\KeywordFeature;

/**
 * Represents a keyword in the query
 */
class KeywordFeatureNode extends ParsedNode {

	/**
	 * @var KeywordFeature $keyword
	 */
	private $keyword;

	/**
	 * @var string
	 */
	private $key;

	/**
	 * @var string
	 */
	private $value;

	/**
	 * @var string
	 */
	private $quotedValue;

	/**
	 * @var string
	 */
	private $delimiter;

	/**
	 * @var string
	 */
	private $suffix;

	/**
	 * SimpleKeywordFeatureNode constructor.
	 * @param int $startOffset
	 * @param int $endOffset
	 * @param KeywordFeature $keyword
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param string $delimiter
	 * @param string $suffix
	 */
	public function __construct(
		$startOffset,
		$endOffset,
		KeywordFeature $keyword,
		$key,
		$value,
		$quotedValue,
		$delimiter,
		$suffix
	) {
		parent::__construct( $startOffset, $endOffset );
		$this->keyword = $keyword;
		$this->key = $key;
		$this->value = $value;
		$this->quotedValue = $quotedValue;
		$this->delimiter = $delimiter;
		$this->suffix = $suffix;
	}

	/**
	 * The feature
	 * @return KeywordFeature
	 */
	public function getKeyword() {
		return $this->keyword;
	}

	/**
	 * The keyword prefix used
	 * @return string
	 */
	public function getKey() {
		return $this->key;
	}

	/**
	 * The value (unescaped)
	 * @return string
	 */
	public function getValue() {
		return $this->value;
	}

	/**
	 * The quoted as-is
	 * @return string
	 */
	public function getQuotedValue() {
		return $this->quotedValue;
	}

	/**
	 * The delimiter used to wrap the value
	 * @return string
	 */
	public function getDelimiter() {
		return $this->delimiter;
	}

	/**
	 * The optional value suffix used in the query
	 * @return string
	 */
	public function getSuffix() {
		return $this->suffix;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return [
			"keyword" => array_merge(
				$this->baseParams(),
				[
					"keyword" => get_class( $this->keyword ),
					"key" => $this->key,
					"value" => $this->value,
					"quotedValue" => $this->quotedValue,
					"delimiter" => $this->delimiter,
					"suffix" => $this->suffix,
				]
			)
		];
	}
}
