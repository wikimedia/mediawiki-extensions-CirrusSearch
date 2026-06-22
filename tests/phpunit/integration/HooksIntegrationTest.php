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
 * @license GPL-2.0-or-later
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\Hooks
 */
class HooksIntegrationTest extends CirrusIntegrationTestCase {

	public static function provideOverrides() {
		return [
			'wgCirrusSearchPhraseRescoreWindowSize normal' => [
				CirrusConfigNames::PhraseRescoreWindowSize,
				1000,
				'cirrusPhraseWindow',
				"50",
				50,
			],
			'wgCirrusSearchPhraseRescoreWindowSize too high' => [
				CirrusConfigNames::PhraseRescoreWindowSize,
				1000,
				'cirrusPhraseWindow',
				"200000",
				1000,
			],
			'wgCirrusSearchPhraseRescoreWindowSize invalid' => [
				CirrusConfigNames::PhraseRescoreWindowSize,
				1000,
				'cirrusPhraseWindow',
				"blah",
				1000,
			],
			'wgCirrusSearchFunctionRescoreWindowSize normal' => [
				CirrusConfigNames::FunctionRescoreWindowSize,
				1000,
				'cirrusFunctionWindow',
				"50",
				50,
			],
			'wgCirrusSearchFunctionRescoreWindowSize too high' => [
				CirrusConfigNames::FunctionRescoreWindowSize,
				1000,
				'cirrusFunctionWindow',
				"20000",
				1000,
			],
			'wgCirrusSearchFunctionRescoreWindowSize invalid' => [
				CirrusConfigNames::FunctionRescoreWindowSize,
				1000,
				'cirrusFunctionWindow',
				"blah",
				1000,
			],
			'wgCirrusSearchFragmentSize normal' => [
				CirrusConfigNames::FragmentSize,
				10,
				'cirrusFragmentSize',
				100,
				100
			],
			'wgCirrusSearchFragmentSize too high' => [
				CirrusConfigNames::FragmentSize,
				10,
				'cirrusFragmentSize',
				100000,
				10
			],
			'wgCirrusSearchFragmentSize invalid' => [
				CirrusConfigNames::FragmentSize,
				10,
				'cirrusFragmentSize',
				'blah',
				10
			],
			'wgCirrusSearchPhraseRescoreBoost' => [
				CirrusConfigNames::PhraseRescoreBoost,
				10,
				'cirrusPhraseBoost',
				'1',
				1
			],
			'wgCirrusSearchPhraseRescoreBoost invalid' => [
				CirrusConfigNames::PhraseRescoreBoost,
				10,
				'cirrusPhraseBoost',
				'blah',
				10,
			],
			'wgCirrusSearchPhraseSlop normal' => [
				CirrusConfigNames::PhraseSlop,
				[ 'boost' => 1 ],
				'cirrusPhraseSlop',
				'10',
				[ 'boost' => 10 ],
			],
			'wgCirrusSearchPhraseSlop too high' => [
				CirrusConfigNames::PhraseSlop,
				[ 'boost' => 1 ],
				'cirrusPhraseSlop',
				'11',
				[ 'boost' => 1 ],
			],
			'wgCirrusSearchPhraseSlop invalid' => [
				CirrusConfigNames::PhraseSlop,
				[ 'boost' => 1 ],
				'cirrusPhraseSlop',
				'blah',
				[ 'boost' => 1 ]
			],
			'wgCirrusSearchLogElasticRequests normal' => [
				CirrusConfigNames::LogElasticRequests,
				true,
				'cirrusLogElasticRequests',
				'secret',
				false,
				[ CirrusConfigNames::LogElasticRequestsSecret => 'secret' ]
			],
			'wgCirrusSearchLogElasticRequests bad secret' => [
				CirrusConfigNames::LogElasticRequests,
				true,
				'cirrusLogElasticRequests',
				'blah',
				true,
				[ CirrusConfigNames::LogElasticRequestsSecret => 'secret' ]
			],
			'wgCirrusSearchEnableAltLanguage activate' => [
				CirrusConfigNames::EnableAltLanguage,
				false,
				'cirrusAltLanguage',
				'yes',
				true,
			],
			'wgCirrusSearchEnableAltLanguage disable' => [
				CirrusConfigNames::EnableAltLanguage,
				true,
				'cirrusAltLanguage',
				'no',
				false,
			],
			'wgCirrusSearchUseCompletionSuggester disable' => [
				CirrusConfigNames::UseCompletionSuggester,
				'yes',
				'cirrusUseCompletionSuggester',
				'no',
				false
			],
			'wgCirrusSearchUseCompletionSuggester cannot be activated' => [
				CirrusConfigNames::UseCompletionSuggester,
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
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'min_doc_freq' => 3 ],
				'cirrusMltMinDocFreq',
				5,
				[ 'min_doc_freq' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig max_doc_freq' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'max_doc_freq' => 3 ],
				'cirrusMltMaxDocFreq',
				5,
				[ 'max_doc_freq' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig max_query_terms' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'max_query_terms' => 3 ],
				'cirrusMltMaxQueryTerms',
				5,
				[ 'max_query_terms' => 5 ],
				[ CirrusConfigNames::MoreLikeThisMaxQueryTermsLimit => 6 ]
			],
			'wgCirrusSearchMoreLikeThisConfig max_query_terms too high' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'max_query_terms' => 3 ],
				'cirrusMltMaxQueryTerms',
				5,
				[ 'max_query_terms' => 3 ],
				[ CirrusConfigNames::MoreLikeThisMaxQueryTermsLimit => 4 ]
			],
			'wgCirrusSearchMoreLikeThisConfig min_term_freq' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'min_term_freq' => 3 ],
				'cirrusMltMinTermFreq',
				5,
				[ 'min_term_freq' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig minimum_should_match' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'minimum_should_match' => '30%' ],
				'cirrusMltMinimumShouldMatch',
				'50%',
				[ 'minimum_should_match' => '50%' ],
			],
			'wgCirrusSearchMoreLikeThisConfig minimum_should_match invalid' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'minimum_should_match' => '30%' ],
				'cirrusMltMinimumShouldMatch',
				'50A%',
				[ 'minimum_should_match' => '30%' ],
			],
			'wgCirrusSearchMoreLikeThisConfig min_word_length' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'min_word_length' => 3 ],
				'cirrusMltMinWordLength',
				5,
				[ 'min_word_length' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisConfig max_word_length' => [
				CirrusConfigNames::MoreLikeThisConfig,
				[ 'max_word_length' => 3 ],
				'cirrusMltMaxWordLength',
				5,
				[ 'max_word_length' => 5 ],
			],
			'wgCirrusSearchMoreLikeThisFields allowed' => [
				CirrusConfigNames::MoreLikeThisFields,
				[ 'title', 'text' ],
				'cirrusMltFields',
				'text,opening_text',
				[ 'text', 'opening_text' ],
				[ CirrusConfigNames::MoreLikeThisAllowedFields => [ 'text', 'opening_text' ] ]
			],
			'wgCirrusSearchMoreLikeThisFields disallowed' => [
				CirrusConfigNames::MoreLikeThisFields,
				[ 'title', 'text' ],
				'cirrusMltFields',
				'text,opening_text,unknown',
				[ 'text', 'opening_text' ],
				[ CirrusConfigNames::MoreLikeThisAllowedFields => [ 'text', 'opening_text' ] ]
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
		$nullOptions = $option === CirrusConfigNames::MoreLikeThisConfig ? [
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
			CirrusConfigNames::UseCompletionSuggester => true,
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
