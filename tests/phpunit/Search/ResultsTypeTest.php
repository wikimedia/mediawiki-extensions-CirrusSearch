<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Searcher;

/**
 * Test escaping search strings.
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
 * @covers \CirrusSearch\Search\FullTextResultsType
 * @group CirrusSearch
 */
class ResultsTypeTest extends CirrusTestCase {
	/**
	 * @dataProvider fullTextHighlightingConfigurationTestCases
	 */
	public function testFullTextHighlightingConfiguration(
		$useExperimentalHighlighter,
		array $highlightSource,
		array $expected
	) {
		$this->setMwGlobals( 'wgCirrusSearchUseExperimentalHighlighter', $useExperimentalHighlighter );
		$type = new FullTextResultsType();
		$this->assertEquals( $expected, $type->getHighlightingConfiguration( $highlightSource ) );
	}

	public static function fullTextHighlightingConfigurationTestCases() {
		$boostBefore = [
			20 => 2,
			50 => 1.8,
			200 => 1.5,
			1000 => 1.2,
		];

		return [
			'default configuration' => [
				false,
				[],
				[
					'pre_tags' => [ json_decode( '"\uE000"' ) ],
					'post_tags' => [ json_decode( '"\uE001"' ) ],
					'fields' => [
						'title' => [
							'number_of_fragments' => 0,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => [ 'title', 'title.plain' ],
						],
						'redirect.title' => [
							'number_of_fragments' => 1,
							'fragment_size' => 10000,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => [ 'redirect.title', 'redirect.title.plain' ],
						],
						'category' => [
							'number_of_fragments' => 1,
							'fragment_size' => 10000,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => [ 'category', 'category.plain' ],
						],
						'heading' => [
							'number_of_fragments' => 1,
							'fragment_size' => 10000,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => [ 'heading', 'heading.plain' ],
						],
						'text' => [
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'fvh',
							'order' => 'score',
							'no_match_size' => 150,
							'matched_fields' => [ 'text', 'text.plain' ],
						],
						'auxiliary_text' => [
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => [ 'auxiliary_text', 'auxiliary_text.plain' ],
						],
						'file_text' => [
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => [ 'file_text', 'file_text.plain' ],
						],
					],
				]
			],
			'default configuration with experimental highlighter' => [
				true,
				[],
				[
					'pre_tags' => [ json_decode( '"\uE000"' ) ],
					'post_tags' => [ json_decode( '"\uE001"' ) ],
					'fields' => [
						'title' => [
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'matched_fields' => [ 'title', 'title.plain' ],
							'fragmenter' => 'none',
						],
						'redirect.title' => [
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'order' => 'score',
							'options' => [ 'skip_if_last_matched' => true ],
							'matched_fields' => [ 'redirect.title', 'redirect.title.plain' ],
							'fragmenter' => 'none',
						],
						'category' => [
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'order' => 'score',
							'options' => [ 'skip_if_last_matched' => true ],
							'matched_fields' => [ 'category', 'category.plain' ],
							'fragmenter' => 'none',
						],
						'heading' => [
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'order' => 'score',
							'options' => [ 'skip_if_last_matched' => true ],
							'matched_fields' => [ 'heading', 'heading.plain' ],
							'fragmenter' => 'none',
						],
						'text' => [
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'experimental',
							'options' => [
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
							],
							'no_match_size' => 150,
							'matched_fields' => [ 'text', 'text.plain' ],
							'fragmenter' => 'scan',
						],
						'auxiliary_text' => [
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'experimental',
							'options' => [
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
								'skip_if_last_matched' => true,
							],
							'matched_fields' => [ 'auxiliary_text', 'auxiliary_text.plain' ],
							'fragmenter' => 'scan',
						],
						'file_text' => [
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'experimental',
							'options' => [
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
								'skip_if_last_matched' => true,
							],
							'matched_fields' => [ 'file_text', 'file_text.plain' ],
							'fragmenter' => 'scan',
						],
					],
				],
			],
			'source configuration with experimental-highlighter' => [
				true,
				[
					'source_text' => [
						[
							'pattern' => '(some|thing)',
							'locale' => 'testlocale',
							'insensitive' => false,
						]
					],
				],
				[
					'pre_tags' => [ json_decode( '"\uE000"' ) ],
					'post_tags' => [ json_decode( '"\uE001"' ) ],
					'fields' => [
						'source_text.plain' => [
							'type' => 'experimental',
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'options' => [
								'regex' => [ '(some|thing)' ],
								'locale' => 'testlocale',
								'regex_flavor' => 'lucene',
								'skip_query' => true,
								'regex_case_insensitive' => false,
								'max_determinized_states' => 20000,
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
							],
							'no_match_size' => 150,
							'fragmenter' => 'scan',
						],
					],
				],
			],
		];
	}

	public function fancyRedirectHandlingProvider() {
		return [
			'typical title only match' => [
				'Trebuchet',
				[
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
					],
				],
			],
			'partial title match' => [
				'Trebuchet',
				[
					'highlight' => [
						'title.prefix' => [
							Searcher::HIGHLIGHT_PRE . 'Trebu' . Searcher::HIGHLIGHT_POST . 'chet',
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
					],
				],
			],
			'full redirect match same namespace' => [
				'Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							Searcher::HIGHLIGHT_PRE . 'Pierriere' . Searcher::HIGHLIGHT_POST,
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 0, 'title' => 'Pierriere' ]
						],
					],
				],
			],
			'full redirect match other namespace' => [
				'Category:Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							Searcher::HIGHLIGHT_PRE . 'Pierriere' . Searcher::HIGHLIGHT_POST,
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 14, 'title' => 'Pierriere' ]
						],
					],
				],
			],
			'partial redirect match other namespace' => [
				'Category:Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							Searcher::HIGHLIGHT_PRE . 'Pi' . Searcher::HIGHLIGHT_POST . 'erriere',
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 14, 'title' => 'Pierriere' ]
						],
					],
				],
			],
			'multiple redirect namespace matches' => [
				'User:Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							Searcher::HIGHLIGHT_PRE . 'Pierriere' . Searcher::HIGHLIGHT_POST,
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 14, 'title' => 'Pierriere' ],
							[ 'namespace' => 2, 'title' => 'Pierriere' ],
						],
					],
				],
				[ 0, 2 ]
			],
		];
	}

	/**
	 * @covers \CirrusSearch\Search\FancyTitleResultsType
	 * @dataProvider fancyRedirectHandlingProvider
	 */
	public function testFancyRedirectHandling( $expected, $hit, array $namespaces = [] ) {
		$type = new FancyTitleResultsType( 'prefix' );
		$result = new \Elastica\Result( $hit );
		$matches = $type->transformOneElasticResult( $result, $namespaces );
		$title = FancyTitleResultsType::chooseBestTitleOrRedirect( $matches );
		$this->assertEquals( $expected, $title->getPrefixedText() );
	}
}
