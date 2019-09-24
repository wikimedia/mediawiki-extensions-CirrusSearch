<?php

namespace CirrusSearch\Test;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\Query\SimpleKeywordFeature;
use CirrusSearch\Query\LegacyKeywordFeature;

class MockSimpleKeywordFeature extends SimpleKeywordFeature implements LegacyKeywordFeature {
	private $calls = [];

	protected function getKeywords() {
		return [ 'mock', 'mock2' ];
	}

	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$this->calls[] = [ $key, $value, $quotedValue, $negated ];
	}

	public function getApplyCallArguments() {
		return $this->calls;
	}
}
