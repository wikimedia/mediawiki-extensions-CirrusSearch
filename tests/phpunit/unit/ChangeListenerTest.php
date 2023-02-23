<?php

namespace CirrusSearch;

class ChangeListenerTest extends CirrusTestCase {
	/**
	 * @covers \CirrusSearch\ChangeListener::prepareTitlesForLinksUpdate()
	 */
	public function testPrepareTitlesForLinksUpdate() {
		$changeListener = new ChangeListener(
			$this->createMock( \JobQueueGroup::class ),
			$this->newHashSearchConfig( [] ),
			$this->createMock( \LoadBalancer::class )
		);
		$titles = [ \Title::makeTitle( NS_MAIN, 'Title1' ), \Title::makeTitle( NS_MAIN, 'Title2' ) ];
		$this->assertEqualsCanonicalizing(
			[ 'Title1', 'Title2' ],
			$changeListener->prepareTitlesForLinksUpdate( $titles, 2 ),
			'All titles must be returned'
		);
		$this->assertCount( 1, $changeListener->prepareTitlesForLinksUpdate( $titles, 1 ) );
		$titles = [ \Title::makeTitle( NS_MAIN, 'Title1' ), \Title::makeTitle( NS_MAIN, 'Title' . chr( 130 ) ) ];
		$this->assertEqualsCanonicalizing( [ 'Title1', 'Title' . chr( 130 ) ],
			$changeListener->prepareTitlesForLinksUpdate( $titles, 2 ),
			'Bad UTF8 links are kept by default'
		);
		$this->assertEquals( [ 'Title1' ], $changeListener->prepareTitlesForLinksUpdate( $titles, 2, true ),
			'Bad UTF8 links can be filtered' );
	}
}
