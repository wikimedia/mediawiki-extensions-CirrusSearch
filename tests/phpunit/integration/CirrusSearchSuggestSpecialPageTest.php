<?php

namespace CirrusSearch;

use MediaWiki\MainConfigNames;
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
			MainConfigNames::LanguageCode => 'he',
			CirrusConfigNames::NamespaceResolutionMethod => 'utr30_with_hebrew_wrong_keyboard',
			CirrusConfigNames::CompletionUseSecondTryProfile => 'language_converter_and_hebrew_wrong_keyboard',
			MainConfigNames::SearchType => CirrusSearch::NAME
		] );
	}

	/**
	 * Test that the second try logic for completion is also applied for special page suggestions
	 * @covers \CirrusSearch\CirrusSearch::suggestSpecialPages
	 * @return void
	 */
	public function testSecondTry(): void {
		$engine = new CirrusSearch();
		$results = $engine->completionSearchWithVariants( 'nhujs:terth' );
		self::assertEquals(
			\SearchSuggestionSet::fromTitles( [ Title::makeTitleSafe( NS_SPECIAL, 'אקראי' ) ] ),
			$results
		);
	}
}
