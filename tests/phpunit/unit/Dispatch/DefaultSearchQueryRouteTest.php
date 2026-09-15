<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\SearchQuery;

/**
 * @covers \CirrusSearch\Dispatch\DefaultSearchQueryRoute
 * @group CirrusSearch
 */
class DefaultSearchQueryRouteTest extends CirrusTestCase {

	/**
	 * Asked only once the election found nothing, so there is no query it can turn down.
	 */
	public function testItTakesEveryQueryItIsAsked() {
		$route = DefaultSearchQueryRoute::fromProfileEntry( SearchQuery::SEARCH_TEXT,
			'cirrus_default',
			[ DefaultSearchQueryRoute::ENTRY_CONTEXT => SearchProfileService::CONTEXT_DEFAULT ] );
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' )->build();

		$decision = $route->decide( $query );
		$this->assertTrue( $decision->isAccepted() );
		$this->assertSame( RouteDecision::REASON_DEFAULT, $decision->getReason() );
		$this->assertSame( SearchProfileService::CONTEXT_DEFAULT, $decision->getProfileContext() );
	}

	/**
	 * The context is the whole of what this route carries, so an entry without one says
	 * nothing at all.
	 */
	public function testAnEntryWithoutAContextIsRejected() {
		$this->expectException( SearchProfileException::class );
		DefaultSearchQueryRoute::fromProfileEntry( SearchQuery::SEARCH_TEXT, 'cirrus_default', [] );
	}
}
