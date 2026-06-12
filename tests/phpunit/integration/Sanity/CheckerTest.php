<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\Connection;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use Wikimedia\Stats\StatsFactory;

/**
 * @covers \CirrusSearch\Sanity\Checker
 * @group CirrusSearch
 * @group Database
 */
class CheckerTest extends CirrusIntegrationTestCase {

	/**
	 * @return int the redirect page's id
	 */
	private function createRedirectPage(): int {
		$target = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, 'CheckerTestTarget' ) );
		$this->editPage( $target, 'Target content' );
		$redirect = $this->getNonexistingTestPage( Title::makeTitle( NS_MAIN, 'CheckerTestRedirect' ) );
		$status = $this->editPage( $redirect, '#REDIRECT [[CheckerTestTarget]]' );
		return $status->getNewRevision()->getPage()->getId();
	}

	private function newSearchConfig( bool $build ): SearchConfig {
		return $this->newHashSearchConfig(
			[ 'CirrusSearchRedirectDocuments' => [ 'build' => $build, 'use' => false ] ],
			[ HashSearchConfig::FLAG_INHERIT ]
		);
	}

	private function newConnection(): Connection {
		$connection = $this->createMock( Connection::class );
		$connection->method( 'getClusterName' )->willReturn( 'default' );
		$connection->method( 'extractIndexSuffix' )->willReturn( 'content' );
		$connection->method( 'getIndexSuffixForNamespace' )->willReturn( 'content' );
		return $connection;
	}

	private function newSearcherReturning( \Elastica\ResultSet $resultSet ): Searcher {
		$searcher = $this->createMock( Searcher::class );
		$searcher->method( 'get' )->willReturn( Status::newGood( $resultSet ) );
		return $searcher;
	}

	private function emptyResultSet(): \Elastica\ResultSet {
		return new \Elastica\ResultSet( new \Elastica\Response( [] ), new \Elastica\Query(), [] );
	}

	/**
	 * @param string $docId the elastic document id the result must carry
	 * @param string|null $pageType page_type stored on the document, or null when absent
	 */
	private function singleResultSet( string $docId, ?string $pageType ): \Elastica\ResultSet {
		$source = [ 'namespace' => NS_MAIN, 'title' => 'CheckerTestRedirect', 'version' => 1 ];
		if ( $pageType !== null ) {
			$source['page_type'] = $pageType;
		}
		$hit = new \Elastica\Result( [
			'_id' => $docId,
			'_index' => 'cirrustestwiki_content_first',
			'_source' => $source,
		] );
		return new \Elastica\ResultSet( new \Elastica\Response( [] ), new \Elastica\Query(), [ $hit ] );
	}

	private function newChecker( SearchConfig $config, Searcher $searcher, Remediator $remediator ): Checker {
		return new Checker(
			$config,
			$this->newConnection(),
			$remediator,
			$searcher,
			StatsFactory::newNull(),
			false // logSane
		);
	}

	/**
	 * Assert the remediator recorded exactly one action of the expected name
	 * and return the arguments it was called with.
	 * @return array the recorded arguments
	 */
	private function assertSingleAction( BufferedRemediator $remediator, string $expected ): array {
		$actions = $remediator->getActions();
		$this->assertCount( 1, $actions );
		[ $action, $args ] = $actions[0];
		$this->assertSame( $expected, $action );
		return $args;
	}

	public function testRedirectNotInIndexUnderBuildIsPageNotInIndex() {
		$pageId = $this->createRedirectPage();

		// Under build a redirect carries a first-class document; a missing one
		// is recorded as pageNotInIndex against the redirect page.
		$remediator = new BufferedRemediator();
		$checker = $this->newChecker(
			$this->newSearchConfig( true ),
			$this->newSearcherReturning( $this->emptyResultSet() ),
			$remediator
		);
		$checker->check( [ $pageId ] );

		$args = $this->assertSingleAction( $remediator, 'pageNotInIndex' );
		$this->assertSame( $pageId, $args[0]->getId() );
	}

	public function testRedirectInIndexWithoutBuildIsRedirectInIndex() {
		$pageId = $this->createRedirectPage();
		$config = $this->newSearchConfig( false );

		// Without build a redirect should not have its own document; finding one
		// is recorded as redirectInIndex carrying the doc id, page and index suffix.
		$remediator = new BufferedRemediator();
		$checker = $this->newChecker(
			$config,
			$this->newSearcherReturning( $this->singleResultSet( $config->makeId( $pageId ), null ) ),
			$remediator
		);
		$checker->check( [ $pageId ] );

		$args = $this->assertSingleAction( $remediator, 'redirectInIndex' );
		$this->assertSame( $config->makeId( $pageId ), $args[0] );
		$this->assertSame( $pageId, $args[1]->getId() );
		$this->assertSame( 'content', $args[2] );
	}
}
