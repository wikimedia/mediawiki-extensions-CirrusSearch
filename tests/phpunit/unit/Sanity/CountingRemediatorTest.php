<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Page\WikiPage;
use Wikimedia\Stats\StatsFactory;

/**
 * @covers \CirrusSearch\Sanity\CountingRemediator
 */
class CountingRemediatorTest extends CirrusTestCase {

	public function testRedirectInIndex() {
		$statsHelper = StatsFactory::newUnitTestingHelper();
		$statsFactory = $statsHelper->getStatsFactory();

		$remediator = new CountingRemediator(
			new BufferedRemediator(),
			static function ( string $problem ) use ( $statsFactory ) {
				return $statsFactory->getCounter( 'testMetric' )
					->setLabel( 'problem', $problem );
			}
		);

		$page = $this->createNoOpMock( WikiPage::class );
		$this->assertSame( [], $statsHelper->consumeAllFormatted() );

		$remediator->redirectInIndex( '42', $page, 'content' );
		$this->assertSame(
			[ 'mediawiki.testMetric:1|c|#problem:redirectInIndex' ],
			$statsHelper->consumeAllFormatted()
		);
	}
}
