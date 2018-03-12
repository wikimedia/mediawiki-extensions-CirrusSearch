<?php

namespace CirrusSearch\Query;

/**
 * @group CirrusSearch
 */
class LanguageFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function testTooManyLanguagesWarning() {
		$this->assertWarnings(
			new LanguageFeature(),
			[ [ 'cirrussearch-feature-too-many-conditions', 'inlanguage', LanguageFeature::QUERY_LIMIT ] ],
			'inlanguage:' . implode( ',', range( 1, 40 ) )
		);
	}

	public function testCategoriesMustExistWarning() {
		$this->assertWarnings(
			new InCategoryFeature( new \HashConfig( [
				'CirrusSearchMaxIncategoryOptions' => 2,
			] ) ),
			[ [ 'cirrussearch-incategory-feature-no-valid-categories', 'incategory' ] ],
			'incategory:id:74,id:18'
		);
	}
}
