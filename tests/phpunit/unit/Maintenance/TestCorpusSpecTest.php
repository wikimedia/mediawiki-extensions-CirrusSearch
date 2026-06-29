<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusTestCase;
use InvalidArgumentException;

/**
 * @covers \CirrusSearch\Maintenance\TestCorpusSpec
 * @covers \CirrusSearch\Maintenance\CorpusEntry
 */
class TestCorpusSpecTest extends CirrusTestCase {

	public function testParsesMixedEntries() {
		$spec = TestCorpusSpec::fromArray( [
			'defaultWiki' => 'cirrustest',
			'pages' => [
				[ 'title' => 'Catapult', 'text' => 'A [[catapult]] is a thing.' ],
				[ 'title' => 'User:Tester/common.js', 'text' => "mw.log('x');", 'model' => 'javascript' ],
				[
					'title' => 'File:Timeline.pdf',
					'file' => 'Timeline.pdf',
					'text' => 'Linux distribution timeline.',
				],
			],
		] );

		$this->assertSame( 'cirrustest', $spec->getDefaultWiki() );
		$entries = $spec->getEntries();
		$this->assertCount( 3, $entries );

		$this->assertSame( 'Catapult', $entries[0]->getTitle() );
		$this->assertSame( 'A [[catapult]] is a thing.', $entries[0]->getText() );
		$this->assertNull( $entries[0]->getModel() );
		$this->assertFalse( $entries[0]->isFile() );
		$this->assertFalse( $entries[0]->isRedirect() );

		$this->assertSame( 'javascript', $entries[1]->getModel() );

		$this->assertTrue( $entries[2]->isFile() );
		$this->assertSame( 'Timeline.pdf', $entries[2]->getFile() );
		$this->assertSame( 'Linux distribution timeline.', $entries[2]->getText() );
	}

	public function testDefaultWikiAppliedWhenEntryHasNoWiki() {
		$spec = TestCorpusSpec::fromArray( [
			'defaultWiki' => 'cirrustest',
			'pages' => [ [ 'title' => 'Foo', 'text' => 'bar' ] ],
		] );
		$this->assertSame( [ 'cirrustest' ], $spec->getEntries()[0]->getWikis() );
	}

	public function testDefaultWikiFallsBackToConstant() {
		$spec = TestCorpusSpec::fromArray( [ 'pages' => [ [ 'title' => 'Foo', 'text' => 'bar' ] ] ] );
		$this->assertSame( TestCorpusSpec::DEFAULT_WIKI, $spec->getDefaultWiki() );
		$this->assertSame( [ TestCorpusSpec::DEFAULT_WIKI ], $spec->getEntries()[0]->getWikis() );
	}

	public function testFileEntryTextIsOptional() {
		$spec = TestCorpusSpec::fromArray( [
			'pages' => [ [ 'title' => 'File:Foo.svg', 'file' => 'Foo.svg' ] ],
		] );
		$entry = $spec->getEntries()[0];
		$this->assertTrue( $entry->isFile() );
		$this->assertNull( $entry->getText() );
	}

	public function testRedirectFieldExpandsToWikitext() {
		$spec = TestCorpusSpec::fromArray( [
			'pages' => [ [ 'title' => 'Amazing', 'redirect' => 'Catapult' ] ],
		] );
		$entry = $spec->getEntries()[0];
		$this->assertTrue( $entry->isRedirect() );
		$this->assertSame( '#REDIRECT [[Catapult]]', $entry->getText() );
	}

	public function testRedirectDetectedFromText() {
		$spec = TestCorpusSpec::fromArray( [
			'pages' => [ [ 'title' => 'Amazing', 'text' => '#REDIRECT [[Catapult]]' ] ],
		] );
		$this->assertTrue( $spec->getEntries()[0]->isRedirect() );
	}

	public function testMultiWikiListIsDeduplicated() {
		$spec = TestCorpusSpec::fromArray( [
			'pages' => [ [
				'title' => 'File:Dup.svg',
				'file' => 'Dup.svg',
				'wiki' => [ 'cirrustest', 'commons', 'cirrustest' ],
			] ],
		] );
		$this->assertSame( [ 'cirrustest', 'commons' ], $spec->getEntries()[0]->getWikis() );
	}

	public function testEmptyWikiListFallsBackToDefault() {
		$spec = TestCorpusSpec::fromArray( [
			'defaultWiki' => 'cirrustest',
			'pages' => [ [ 'title' => 'Foo', 'text' => 'bar', 'wiki' => [] ] ],
		] );
		$this->assertSame( [ 'cirrustest' ], $spec->getEntries()[0]->getWikis() );
	}

	public function testEntriesForWikiFiltersByTarget() {
		$spec = TestCorpusSpec::fromArray( [
			'defaultWiki' => 'cirrustest',
			'pages' => [
				[ 'title' => 'Local', 'text' => 'x' ],
				[ 'title' => 'OnCommons', 'text' => 'y', 'wiki' => 'commons' ],
				[ 'title' => 'Both', 'text' => 'z', 'wiki' => [ 'cirrustest', 'commons' ] ],
			],
		] );

		$cirrus = array_map( static fn ( $e ) => $e->getTitle(), $spec->entriesForWiki( 'cirrustest' ) );
		$this->assertSame( [ 'Local', 'Both' ], $cirrus );

		$commons = array_map( static fn ( $e ) => $e->getTitle(), $spec->entriesForWiki( 'commons' ) );
		$this->assertSame( [ 'OnCommons', 'Both' ], $commons );
	}

	public function testEntriesForWikiDefaultsToCorpusDefault() {
		$spec = TestCorpusSpec::fromArray( [
			'defaultWiki' => 'cirrustest',
			'pages' => [ [ 'title' => 'Local', 'text' => 'x' ] ],
		] );
		$this->assertCount( 1, $spec->entriesForWiki() );
	}

	public function testRedirectsAreOrderedLast() {
		$spec = TestCorpusSpec::fromArray( [
			'pages' => [
				[ 'title' => 'RedirA', 'redirect' => 'Target' ],
				[ 'title' => 'Page1', 'text' => 'x' ],
				[ 'title' => 'RedirB', 'redirect' => 'Target' ],
				[ 'title' => 'Page2', 'text' => 'y' ],
			],
		] );
		$titles = array_map( static fn ( $e ) => $e->getTitle(), $spec->entriesForWiki() );
		// Plain pages keep declaration order and precede the redirects.
		$this->assertSame( [ 'Page1', 'Page2', 'RedirA', 'RedirB' ], $titles );
	}

	public function testFromYamlRoundTrip() {
		$yaml = <<<YAML
		defaultWiki: cirrustest
		pages:
		  - title: Catapult
		    text: A [[catapult]] is a thing.
		  - title: Amazing
		    redirect: Catapult
		YAML;
		$spec = TestCorpusSpec::fromYaml( $yaml );
		$this->assertSame( 'cirrustest', $spec->getDefaultWiki() );
		$this->assertCount( 2, $spec->getEntries() );
		$this->assertTrue( $spec->getEntries()[1]->isRedirect() );
	}

	public function testGroupsCarryTagsWikiDefaultAndDescription() {
		$spec = TestCorpusSpec::fromArray( [
			'defaultWiki' => 'cirrustest',
			'groups' => [
				[
					'tags' => [ '@setup_main' ],
					'description' => 'Core articles.',
					'pages' => [ [ 'title' => 'Catapult', 'text' => 'x' ] ],
				],
				[
					'tags' => [ '@commons', '@filesearch' ],
					'wiki' => 'commons',
					'pages' => [ [ 'title' => 'File:OnCommons.svg', 'file' => 'OnCommons.svg' ] ],
				],
			],
		] );

		$entries = $spec->getEntries();
		$this->assertCount( 2, $entries );
		$this->assertSame( 'Catapult', $entries[0]->getTitle() );
		$this->assertSame( [ '@setup_main' ], $entries[0]->getTags() );
		$this->assertSame( [ 'cirrustest' ], $entries[0]->getWikis() );
		$this->assertSame( [ '@commons', '@filesearch' ], $entries[1]->getTags() );
		$this->assertSame( [ 'commons' ], $entries[1]->getWikis() );
	}

	public function testPerPageTagsMergeWithGroupTags() {
		$spec = TestCorpusSpec::fromArray( [
			'groups' => [ [
				'tags' => [ '@group' ],
				'pages' => [ [ 'title' => 'Foo', 'text' => 'x', 'tags' => [ '@page', '@group' ] ] ],
			] ],
		] );
		// Group tags first, then per-page tags, de-duplicated.
		$this->assertSame( [ '@group', '@page' ], $spec->getEntries()[0]->getTags() );
	}

	public function testPerPageWikiOverridesGroupWiki() {
		$spec = TestCorpusSpec::fromArray( [
			'groups' => [ [
				'tags' => [ '@t' ],
				'wiki' => 'commons',
				'pages' => [
					[ 'title' => 'A', 'text' => 'x' ],
					[ 'title' => 'B', 'text' => 'y', 'wiki' => [ 'cirrustest', 'commons' ] ],
				],
			] ],
		] );
		$entries = $spec->getEntries();
		$this->assertSame( [ 'commons' ], $entries[0]->getWikis() );
		$this->assertSame( [ 'cirrustest', 'commons' ], $entries[1]->getWikis() );
	}

	public function testTextFileEntry() {
		$spec = TestCorpusSpec::fromArray( [
			'pages' => [ [ 'title' => 'FromFile', 'textFile' => '../articles/foo.txt' ] ],
		] );
		$entry = $spec->getEntries()[0];
		$this->assertSame( '../articles/foo.txt', $entry->getTextFile() );
		$this->assertNull( $entry->getText() );
		$this->assertFalse( $entry->isFile() );
	}

	public function testFlatPagesHaveNoTags() {
		$spec = TestCorpusSpec::fromArray( [
			'pages' => [ [ 'title' => 'Foo', 'text' => 'x' ] ],
		] );
		$this->assertSame( [], $spec->getEntries()[0]->getTags() );
	}

	public function testGroupWikiAcceptsList() {
		$spec = TestCorpusSpec::fromArray( [
			'groups' => [ [
				'tags' => [ '@t' ],
				'wiki' => [ 'cirrustest', 'commons' ],
				'pages' => [ [ 'title' => 'A', 'text' => 'x' ] ],
			] ],
		] );
		$this->assertSame( [ 'cirrustest', 'commons' ], $spec->getEntries()[0]->getWikis() );
	}

	public function testKnownWikisListsDistinctSorted() {
		$spec = TestCorpusSpec::fromArray( [
			'defaultWiki' => 'cirrustest',
			'groups' => [
				[ 'tags' => [ '@a' ], 'wiki' => 'ru', 'pages' => [ [ 'title' => 'A', 'text' => 'x' ] ] ],
				[ 'tags' => [ '@b' ], 'pages' => [
					[ 'title' => 'B', 'text' => 'y' ],
					[ 'title' => 'C', 'text' => 'z', 'wiki' => [ 'commons', 'cirrustest' ] ],
				] ],
			],
		] );
		$this->assertSame( [ 'cirrustest', 'commons', 'ru' ], $spec->knownWikis() );
	}

	public function testRedirectsOrderedLastAcrossGroups() {
		$spec = TestCorpusSpec::fromArray( [
			'groups' => [
				[ 'tags' => [ '@a' ], 'pages' => [ [ 'title' => 'RedirA', 'redirect' => 'Target' ] ] ],
				[ 'tags' => [ '@b' ], 'pages' => [ [ 'title' => 'Target', 'text' => 'x' ] ] ],
			],
		] );
		$titles = array_map( static fn ( $e ) => $e->getTitle(), $spec->entriesForWiki() );
		// The redirect (declared in the first group) is ordered after the target (second group).
		$this->assertSame( [ 'Target', 'RedirA' ], $titles );
	}

	public function testKnownWikisEmptyForEmptyCorpus() {
		$spec = TestCorpusSpec::fromArray( [ 'groups' => [] ] );
		$this->assertSame( [], $spec->getEntries() );
		$this->assertSame( [], $spec->knownWikis() );
	}

	/**
	 * @dataProvider provideInvalidCorpora
	 */
	public function testInvalidCorpusThrows( array $data, string $expectedMessageFragment ) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/' . preg_quote( $expectedMessageFragment, '/' ) . '/' );
		TestCorpusSpec::fromArray( $data );
	}

	public static function provideInvalidCorpora() {
		return [
			'neither pages nor groups' => [ [ 'defaultWiki' => 'x' ], "exactly one of 'pages' or 'groups'" ],
			'both pages and groups' => [ [ 'pages' => [], 'groups' => [] ], "exactly one of 'pages' or 'groups'" ],
			'groups not a list' => [ [ 'groups' => [ 'a' => [ 'pages' => [] ] ] ], "'groups' must be a list" ],
			'group missing pages' => [ [ 'groups' => [ [ 'tags' => [ '@t' ] ] ] ], "must contain a 'pages' list" ],
			'group description not a string' => [
				[ 'groups' => [ [ 'description' => 123, 'pages' => [] ] ] ],
				"'description' must be a string",
			],
			'pages not a list' => [ [ 'pages' => [ 'a' => [ 'title' => 'T', 'text' => 'x' ] ] ], "must be a list" ],
			'entry not a map' => [ [ 'pages' => [ 'not-a-map' ] ], 'must be a map' ],
			'missing title' => [ [ 'pages' => [ [ 'text' => 'x' ] ] ], "missing a non-empty 'title'" ],
			'blank title' => [ [ 'pages' => [ [ 'title' => '  ', 'text' => 'x' ] ] ], "missing a non-empty 'title'" ],
			'plain page without text' => [ [ 'pages' => [ [ 'title' => 'T' ] ] ], "requires 'text'" ],
			'file and redirect' => [
				[ 'pages' => [ [ 'title' => 'T', 'file' => 'f.svg', 'redirect' => 'X' ] ] ],
				"cannot set both 'file' and 'redirect'",
			],
			'bad model' => [
				[ 'pages' => [ [ 'title' => 'T', 'text' => 'x', 'model' => 'bogus' ] ] ],
				"'model' must be one of",
			],
			'redirect with non-wikitext model' => [
				[ 'pages' => [ [ 'title' => 'T', 'redirect' => 'X', 'model' => 'javascript' ] ] ],
				'redirects must use the wikitext model',
			],
			'bad wiki entry' => [
				[ 'pages' => [ [ 'title' => 'T', 'text' => 'x', 'wiki' => [ '' ] ] ] ],
				"'wiki' entries must be non-empty strings",
			],
			'empty defaultWiki' => [
				[ 'defaultWiki' => '', 'pages' => [] ],
				"'defaultWiki' must be a non-empty string",
			],
			'text and textFile' => [
				[ 'pages' => [ [ 'title' => 'T', 'text' => 'x', 'textFile' => 'f.txt' ] ] ],
				"cannot set both 'text' and 'textFile'",
			],
			'redirect and text' => [
				[ 'pages' => [ [ 'title' => 'T', 'redirect' => 'X', 'text' => 'y' ] ] ],
				"'redirect' cannot be combined with 'text'/'textFile'",
			],
			'bad tags entry' => [
				[ 'pages' => [ [ 'title' => 'T', 'text' => 'x', 'tags' => [ '' ] ] ] ],
				"'tags' entries must be non-empty strings",
			],
		];
	}
}
