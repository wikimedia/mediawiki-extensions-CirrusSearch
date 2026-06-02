<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\NearMatchQueryBuilder
 */
class NearMatchQueryBuilderTest extends CirrusTestCase {

	public function testAssembledQueryCarriesPageTypeFilter() {
		// Near-match inherits the redirect-exclusion filter from the assembled query.
		$qb = new NearMatchQueryBuilder();
		$context = new SearchContext( $this->newHashSearchConfig(), null, null, null, null,
			$this->createCirrusSearchHookRunner() );
		$qb->build( $context, 'Some Title' );
		$this->assertExcludesRedirectDocuments( $context->getQuery() );
	}
}
