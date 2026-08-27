<?php

namespace CirrusSearch\Test;

use CirrusSearch\Query\LegacyKeywordFeature;
use CirrusSearch\Query\SimpleKeywordFeature;
use CirrusSearch\Search\SearchContext;

class MockSimpleKeywordFeature extends SimpleKeywordFeature implements LegacyKeywordFeature {
	/** @var array[] */
	private $calls = [];

	private bool $queryHeader;

	/**
	 * @param bool $queryHeader true to behave like a query header keyword, which works only
	 *  at the start of the query
	 */
	public function __construct( bool $queryHeader = false ) {
		$this->queryHeader = $queryHeader;
	}

	/** @inheritDoc */
	protected function getKeywords() {
		return [ 'mock', 'mock2' ];
	}

	/** @inheritDoc */
	public function queryHeader() {
		return $this->queryHeader;
	}

	/** @inheritDoc */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$this->calls[] = [ $key, $value, $quotedValue, $negated ];
	}

	/**
	 * @return array[]
	 */
	public function getApplyCallArguments() {
		return $this->calls;
	}
}
