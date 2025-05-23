<?php

namespace CirrusSearch\Test;

use CirrusSearch\Query\LegacyKeywordFeature;
use CirrusSearch\Query\SimpleKeywordFeature;
use CirrusSearch\Search\SearchContext;

class MockSimpleKeywordFeature extends SimpleKeywordFeature implements LegacyKeywordFeature {
	/** @var array[] */
	private $calls = [];

	/** @inheritDoc */
	protected function getKeywords() {
		return [ 'mock', 'mock2' ];
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
