<?php

namespace CirrusSearch\Query;

use CirrusSearch\HashSearchConfig;
use CirrusSearch\SearchConfig;
use CirrusSearch\Search\SearchContext;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * Test More Like This keyword feature.
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
 * @covers \CirrusSearch\Query\MoreLikeFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class MoreLikeFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function applyProvider() {
		return [
			'doesnt eat unrelated queries' => [
				'other stuff',
				new \Elastica\Query\MatchAll(),
				false,
			],
			'morelike is a queryHeader but ideally should not' => [
				'other stuff morelike:Test',
				new \Elastica\Query\MatchAll(),
				false,
			],
			'no query given for unknown page' => [
				'morelike:Does not exist or at least I hope not',
				null,
				true,
			],
			'single page morelike' => [
				'morelike:Some page',
				( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '12345' ],
					] ),
				true,
			],
			'single page morelike w/wikibase' => [
				'morelikewithwikibase:Some page',
				( new \Elastica\Query\BoolQuery() )
					->addFilter( new \Elastica\Query\Exists( 'wikibase_item' ) )
					->addMust( ( new \Elastica\Query\MoreLikeThis() )
						->setParams( [
							'min_doc_freq' => 2,
							'max_doc_freq' => null,
							'max_query_terms' => 25,
							'min_term_freq' => 2,
							'min_word_length' => 0,
							'max_word_length' => 0,
							'minimum_should_match' => '30%',
						] )
						->setFields( [ 'text' ] )
						->setLike( [
							[ '_id' => '12345' ],
						] )
					),
				true,
			],
			'multi page morelike' => [
				'morelike:Some page|Other page',
				( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '23456' ],
						[ '_id' => '12345' ],
					] ),
				true,
			],
			'multi page morelike with only one valid' => [
				'morelike:Some page|Does not exist or at least I hope not',
				( new \Elastica\Query\MoreLikeThis() )
					->setParams( [
						'min_doc_freq' => 2,
						'max_doc_freq' => null,
						'max_query_terms' => 25,
						'min_term_freq' => 2,
						'min_word_length' => 0,
						'max_word_length' => 0,
						'minimum_should_match' => '30%',
					] )
					->setFields( [ 'text' ] )
					->setLike( [
						[ '_id' => '12345' ],
					] ),
				true,
			],
		];
	}

	/**
	 * @dataProvider applyProvider
	 */
	public function testApply( $term, $expectedQuery, $mltUsed ) {
		// Inject fake pages for MoreLikeFeature::collectTitles() to find
		$linkCache = MediaWikiServices::getInstance()->getLinkCache();
		$linkCache->addGoodLinkObj( 12345, Title::newFromText( 'Some page' ) );
		$linkCache->addGoodLinkObj( 23456, Title::newFromText( 'Other page' ) );

		// @todo Use a HashConfig with explicit values?
		$config = new HashSearchConfig( [ 'CirrusSearchMoreLikeThisTTL' => 600 ], [ 'inherit' ] );

		$context = new SearchContext( $config );

		// Finally run the test
		$feature = new MoreLikeFeature( $config );

		$result = $feature->apply( $context, $term );

		$this->assertEquals( $mltUsed, $context->isSyntaxUsed( 'more_like' ) );
		if ( $mltUsed ) {
			$this->assertGreaterThan( 0, $context->getCacheTtl() );
		} else {
			$this->assertEquals( 0, $context->getCacheTtl() );
		}
		if ( $expectedQuery === null ) {
			$this->assertFalse( $context->areResultsPossible() );
		} else {
			$this->assertEquals( $expectedQuery, $context->getQuery() );
			if ( $expectedQuery instanceof \Elastica\Query\MatchAll ) {
				$this->assertEquals( $term, $result, 'Term must be unchanged' );
			} else {
				$this->assertEquals( '', $result, 'Term must be empty string' );
			}
		}
	}

	public function testWarningsForUnknownPages() {
		MediaWikiServices::getInstance()->getLinkCache()
			->addGoodLinkObj( 12345, Title::newFromText( 'Some page' ) );
		$this->assertWarnings(
			new MoreLikeFeature( new SearchConfig() ),
			[],
			'morelike:Some page'
		);
		$this->assertWarnings(
			new MoreLikeFeature( new SearchConfig() ),
			[],
			'morelike:Some page|Title that doesnt exist'
		);
		$this->assertWarnings(
			new MoreLikeFeature( new SearchConfig() ),
			[ [ 'cirrussearch-mlt-feature-no-valid-titles', 'morelike' ] ],
			'morelike:Title that doesnt exist'
		);
	}
}
