<?php

namespace CirrusSearch;

use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Profile\SearchProfileServiceFactory;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\User;

/**
 * Make sure cirrus doens't break any hooks.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\Hooks
 */
class HooksIntegrationTest extends CirrusIntegrationTestCase {

	public static function provideOverrides() {
		return [
			'wgCirrusSearchPhraseRescoreWindowSize normal' => [
				'CirrusSearchPhraseRescoreWindowSize',
				1000,
				'cirrusPhraseWindow',
				"50",
				50,
			],
			'wgCirrusSearchPhraseRescoreWindowSize too high' => [
				'CirrusSearchPhraseRescoreWindowSize',
				1000,
				'cirrusPhraseWindow',
				"200000",
				1000,
			],
			'wgCirrusSearchPhraseRescoreWindowSize invalid' => [
				'CirrusSearchPhraseRescoreWindowSize',
				1000,
				'cirrusPhraseWindow',
				"blah",
				1000,
			],
			'wgCirrusSearchFunctionRescoreWindowSize normal' => [
				'CirrusSearchFunctionRescoreWindowSize',
				1000,
				'cirrusFunctionWindow',
				"50",
				50,
			],
			'wgCirrusSearchFunctionRescoreWindowSize too high' => [
				'CirrusSearchFunctionRescoreWindowSize',
				1000,
				'cirrusFunctionWindow',
				"20000",
				1000,
			],
			'wgCirrusSearchFunctionRescoreWindowSize invalid' => [
				'CirrusSearchFunctionRescoreWindowSize',
				1000,
				'cirrusFunctionWindow',
				"blah",
				1000,
			],
			'wgCirrusSearchFragmentSize normal' => [
				'CirrusSearchFragmentSize',
				10,
				'cirrusFragmentSize',
				100,
				100
			],
			'wgCirrusSearchFragmentSize too high' => [
				'CirrusSearchFragmentSize',
				10,
				'cirrusFragmentSize',
				100000,
				10
			],
			'wgCirrusSearchFragmentSize invalid' => [
				'CirrusSearchFragmentSize',
				10,
				'cirrusFragmentSize',
				'blah',
				10
			],
			'wgCirrusSearchPhraseRescoreBoost' => [
				'CirrusSearchPhraseRescoreBoost',
				10,
				'cirrusPhraseBoost',
				'1',
				1
			],
			'wgCirrusSearchPhraseRescoreBoost invalid' => [
				'CirrusSearchPhraseRescoreBoost',
				10,
				'cirrusPhraseBoost',
				'blah',
				10,
			],
			'wgCirrusSearchPhraseSlop normal' => [
				'CirrusSearchPhraseSlop',
				[ 'boost' => 1 ],
				'cirrusPhraseSlop',
				'10',
				[ 'boost' => 10 ],
			],
			'wgCirrusSearchPhraseSlop too high' => [
				'CirrusSearchPhraseSlop',
				[ 'boost' => 1 ],
				'cirrusPhraseSlop',
				'11',
				[ 'boost' => 1 ],
			],
			'wgCirrusSearchPhraseSlop invalid' => [
				'CirrusSearchPhraseSlop',
				[ 'boost' => 1 ],
				'cirrusPhraseSlop',
				'blah',
				[ 'boost' => 1 ]
			],
			'wgCirrusSearchLogElasticRequests normal' => [
				'CirrusSearchLogElasticRequests',
				true,
				'cirrusLogElasticRequests',
				'secret',
				false,
				[ 'CirrusSearchLogElasticRequestsSecret' => 'secret' ]
			],
			'wgCirrusSearchLogElasticRequests bad secret' => [
				'CirrusSearchLogElasticRequests',
				true,
				'cirrusLogElasticRequests',
				'blah',
				true,
				[ 'CirrusSearchLogElasticRequestsSecret' => 'secret' ]
			],
			'wgCirrusSearchEnableAltLanguage activate' => [
				'CirrusSearchEnableAltLanguage',
				false,
				'cirrusAltLanguage',
				'yes',
				true,
			],
			'wgCirrusSearchEnableAltLanguage disable' => [
				'CirrusSearchEnableAltLanguage',
				true,
				'cirrusAltLanguage',
				'no',
				false,
			],
			'wgCirrusSearchUseCompletionSuggester disable' => [
				'CirrusSearchUseCompletionSuggester',
				'yes',
				'cirrusUseCompletionSuggester',
				'no',
				false
			],
			'wgCirrusSearchUseCompletionSuggester cannot be activated' => [
				'CirrusSearchUseCompletionSuggester',
				'no',
				'cirrusUseCompletionSuggester',
				'yes',
				'no'
			],
		];
	}

	/**
	 * @dataProvider provideOverrides
	 * @covers \CirrusSearch\Hooks::initializeForRequest
	 * @covers \CirrusSearch\Hooks::overrideYesNo
	 * @covers \CirrusSearch\Hooks::overrideSecret
	 * @covers \CirrusSearch\Hooks::overrideNumeric
	 * @covers \CirrusSearch\Hooks::overrideSecret
	 */
	public function testOverrides( $option, $originalValue, $paramName, $paramValue, $expectedValue,
		$additionalConfig = []
	) {
		$this->assertArrayHasKey( "wg$option", $GLOBALS );
		$this->overrideConfigValues(
			[ $option => $originalValue ] + $additionalConfig
		);

		$request = new FauxRequest( [ $paramName . "Foo" => $paramValue ] );
		Hooks::initializeForRequest( $request );
		$this->assertEquals( $originalValue, $GLOBALS["wg$option"],
			'Unrelated param does not affect overrides' );

		$request = new FauxRequest( [ $paramName => $paramValue ] );
		Hooks::initializeForRequest( $request );
		$this->assertEquals( $expectedValue, $GLOBALS["wg$option"] );
	}

	public static function provideMltOverrides() {
		return [
			'wgCirrusSearchMoreLikeThisConfig min_doc_freq' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'min_doc_freq' => 3 ],
				'cirrusMltMinDocFreq',
				5,
				[ 'min_doc_freq' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig max_doc_freq' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'max_doc_freq' => 3 ],
				'cirrusMltMaxDocFreq',
				5,
				[ 'max_doc_freq' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig max_query_terms' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'max_query_terms' => 3 ],
				'cirrusMltMaxQueryTerms',
				5,
				[ 'max_query_terms' => 5 ],
				[ 'CirrusSearchMoreLikeThisMaxQueryTermsLimit' => 6 ]
			],
			'wgCirrusSearchMoreLikeThisConfig max_query_terms too high' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'max_query_terms' => 3 ],
				'cirrusMltMaxQueryTerms',
				5,
				[ 'max_query_terms' => 3 ],
				[ 'CirrusSearchMoreLikeThisMaxQueryTermsLimit' => 4 ]
			],
			'wgCirrusSearchMoreLikeThisConfig min_term_freq' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'min_term_freq' => 3 ],
				'cirrusMltMinTermFreq',
				5,
				[ 'min_term_freq' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig minimum_should_match' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'minimum_should_match' => '30%' ],
				'cirrusMltMinimumShouldMatch',
				'50%',
				[ 'minimum_should_match' => '50%' ],
			],
			'wgCirrusSearchMoreLikeThisConfig minimum_should_match invalid' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'minimum_should_match' => '30%' ],
				'cirrusMltMinimumShouldMatch',
				'50A%',
				[ 'minimum_should_match' => '30%' ],
			],
			'wgCirrusSearchMoreLikeThisConfig min_word_length' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'min_word_length' => 3 ],
				'cirrusMltMinWordLength',
				5,
				[ 'min_word_length' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig max_word_length' => [
				'CirrusSearchMoreLikeThisConfig',
				[ 'max_word_length' => 3 ],
				'cirrusMltMaxWordLength',
				5,
				[ 'max_word_length' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisFields allowed' => [
				'CirrusSearchMoreLikeThisFields',
				[ 'title', 'text' ],
				'cirrusMltFields',
				'text,opening_text',
				[ 'text', 'opening_text' ],
				[ 'CirrusSearchMoreLikeThisAllowedFields' => [ 'text', 'opening_text' ] ]
			],
			'wgCirrusSearchMoreLikeThisFields disallowed' => [
				'CirrusSearchMoreLikeThisFields',
				[ 'title', 'text' ],
				'cirrusMltFields',
				'text,opening_text,unknown',
				[ 'text', 'opening_text' ],
				[ 'CirrusSearchMoreLikeThisAllowedFields' => [ 'text', 'opening_text' ] ]
			],
		];
	}

	/**
	 * @covers \CirrusSearch\Hooks::overrideMoreLikeThisOptions()
	 * @covers \CirrusSearch\Hooks::overrideMinimumShouldMatch()
	 * @dataProvider provideMltOverrides
	 * @param string $option
	 * @param mixed $originalValue
	 * @param string $paramName
	 * @param string $paramValue
	 * @param mixed $expectedValue
	 * @param array $additionalConfig
	 */
	public function testMltOverrides( $option, $originalValue, $paramName, $paramValue,
		$expectedValue, $additionalConfig = []
	) {
		$this->assertArrayHasKey( "wg$option", $GLOBALS );
		$nullOptions = $option === 'CirrusSearchMoreLikeThisConfig' ? [
			'min_doc_freq' => null,
			'max_doc_freq' => null,
			'max_query_terms' => null,
			'min_term_freq' => null,
			'min_word_length' => null,
			'max_word_length' => null,
			'minimum_should_match' => null,
		] : [];
		// Hooks use byref method on $array['value'], this creates an null entry if nothing is assigned to it.
		$originalValue += $nullOptions;
		$this->overrideConfigValues(
			[ $option => $originalValue ] + $additionalConfig
		);

		$request = new FauxRequest( [ $paramName . "Foo" => $paramValue ] );
		Hooks::initializeForRequest( $request );
		$this->assertEquals( $originalValue, $GLOBALS["wg$option"],
			'Unrelated param does not affect overrides' );

		$request = new FauxRequest( [ $paramName => $paramValue ] );
		Hooks::initializeForRequest( $request );
		$this->assertEquals( $expectedValue + $nullOptions, $GLOBALS["wg$option"] );
	}

	private function preferencesForCompletionProfiles( array $profiles ) {
		OutputPage::setupOOUI();
		$this->overrideConfigValues( [
			'CirrusSearchUseCompletionSuggester' => true,
		] );
		$service = new SearchProfileService( $this->getServiceContainer()->getUserOptionsLookup() );
		$service->registerDefaultProfile( SearchProfileService::COMPLETION,
			SearchProfileService::CONTEXT_DEFAULT, 'fuzzy' );
		$service->registerArrayRepository( SearchProfileService::COMPLETION, 'phpunit', $profiles );
		$factory = $this->createMock( SearchProfileServiceFactory::class );
		$factory->method( 'loadService' )
			->willReturn( $service );
		$this->setService( SearchProfileServiceFactory::SERVICE_NAME, $factory );

		$prefs = [];
		$hooks = new Hooks(
			$this->createMock( ConfigFactory::class )
		);
		$hooks->onGetPreferences( new User(), $prefs );
		return $prefs;
	}

	public function testNoSearchPreferencesWhenNoChoice() {
		$prefs = $this->preferencesForCompletionProfiles( [] );
		$this->assertEquals( [], $prefs );
	}

	public function testNoSearchPreferencesWhenOnlyOneChoice() {
		$prefs = $this->preferencesForCompletionProfiles( [
			'fuzzy' => [ 'name' => 'fuzzy' ],
		] );
		$this->assertEquals( [], $prefs );
	}

	public function testSearchPreferencesAvailableWithMultipleChoices() {
		$prefs = $this->preferencesForCompletionProfiles( [
			'fuzzy' => [ 'name' => 'fuzzy' ],
			'strict' => [ 'name' => 'strict' ],
		] );
		$this->assertCount( 1, $prefs );
		$this->assertArrayHasKey( 'cirrussearch-pref-completion-profile', $prefs );
	}
}
