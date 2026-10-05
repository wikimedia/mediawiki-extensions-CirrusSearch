<?php

namespace CirrusSearch;

use MediaWiki\MainConfigNames;
use MediaWiki\Search\SearchSuggestionSet;
use MediaWiki\Title\Title;

/**
 * Special pages need the database...
 * @group Database
 * @group CirrusSearch
 */
class CirrusSearchSuggestSpecialPageTest extends \MediaWikiLangTestCase {
	public function setUp(): void {
		$this->overrideConfigValues( [
			MainConfigNames::SpecialPages => [],
			MainConfigNames::SearchType => CirrusSearch::NAME
		] );
	}

	public static function provideTestSecondTry(): \Generator {
		yield 'test duplicates' => [
			'special:versio',
			[ 'Version' ],
			[
				CirrusConfigNames::CompletionUseSecondTryProfile => 'language_converter',
			]
		];
		yield 'test second try' => [
			'nhujs:terth',
			[ 'אקראי' ],
			[
				MainConfigNames::LanguageCode => 'he',
				CirrusConfigNames::NamespaceResolutionMethod => 'utr30_with_hebrew_wrong_keyboard',
				CirrusConfigNames::CompletionUseSecondTryProfile => 'language_converter_and_hebrew_wrong_keyboard',
			]
		];
	}

	/**
	 * Test that the second try logic for completion is also applied for special page suggestions
	 * @covers \CirrusSearch\CirrusSearch::suggestSpecialPages
	 * @dataProvider provideTestSecondTry
	 */
	public function testSuggestSpecialPages( string $search, array $expectedResults, array $config ): void {
		$this->overrideConfigValues( $config );
		$engine = new CirrusSearch();
		$results = $engine->completionSearchWithVariants( $search );
		self::assertEquals(
			SearchSuggestionSet::fromTitles( array_map(
				static fn ( string $specialPageName ): Title => Title::makeTitleSafe( NS_SPECIAL, $specialPageName ),
				$expectedResults
			) ),
			$results
		);
	}
}
