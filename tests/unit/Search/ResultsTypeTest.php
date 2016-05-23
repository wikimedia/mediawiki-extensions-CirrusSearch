<?php

namespace CirrusSearch\Search;

use MediaWikiTestCase;

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
 */
class ResultsTypeTest extends MediaWikiTestCase {
	/**
	 * @dataProvider fullTextHighlightingConfigurationTestCases
	 */
	public function testFullTextHighlightingConfiguration(
		$highlightingConfig,
		$useExperimentalHighlighter,
		array $highlightSource,
		array $expected
	) {
		$this->setMwGlobals( 'wgCirrusSearchUseExperimentalHighlighter', $useExperimentalHighlighter );
		$type = new FullTextResultsType( $highlightingConfig, '' );
		$this->assertEquals( $expected, $type->getHighlightingConfiguration( $highlightSource ) );
	}

	public static function fullTextHighlightingConfigurationTestCases() {
		$boostBefore = array(
			20 => 2,
			50 => 1.8,
			200 => 1.5,
			1000 => 1.2,
		);

		return array(
			'default configuration' => array(
				FullTextResultsType::HIGHLIGHT_ALL,
				false,
				array(),
				array(
					'pre_tags' => array( '<span class="searchmatch">' ),
					'post_tags' => array( '</span>' ),
					'fields' => array(
						'title' => array(
							'number_of_fragments' => 0,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => array( 'title', 'title.plain' ),
						),
						'redirect.title' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 10000,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => array( 'redirect.title', 'redirect.title.plain' ),
						),
						'category' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 10000,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => array( 'category', 'category.plain' ),
						),
						'heading' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 10000,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => array( 'heading', 'heading.plain' ),
						),
						'text' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'fvh',
							'order' => 'score',
							'no_match_size' => 150,
							'matched_fields' => array( 'text', 'text.plain' ),
						),
						'auxiliary_text' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => array( 'auxiliary_text', 'auxiliary_text.plain' ),
						),
						'file_text' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'fvh',
							'order' => 'score',
							'matched_fields' => array( 'file_text', 'file_text.plain' ),
						),
					),
				)
			),
			'default configuration with experimental highlighter' => array(
				FullTextResultsType::HIGHLIGHT_ALL,
				true,
				array(),
				array(
					'pre_tags' => array( '<span class="searchmatch">' ),
					'post_tags' => array( '</span>' ),
					'fields' => array(
						'title' => array(
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'matched_fields' => array( 'title', 'title.plain' ),
							'fragmenter' => 'none',
						),
						'redirect.title' => array(
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'order' => 'score',
							'options' => array( 'skip_if_last_matched' => true ),
							'matched_fields' => array( 'redirect.title', 'redirect.title.plain' ),
							'fragmenter' => 'none',
						),
						'category' => array(
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'order' => 'score',
							'options' => array( 'skip_if_last_matched' => true ),
							'matched_fields' => array( 'category', 'category.plain' ),
							'fragmenter' => 'none',
						),
						'heading' => array(
							'number_of_fragments' => 1,
							'type' => 'experimental',
							'order' => 'score',
							'options' => array( 'skip_if_last_matched' => true ),
							'matched_fields' => array( 'heading', 'heading.plain' ),
							'fragmenter' => 'none',
						),
						'text' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'experimental',
							'options' => array(
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
							),
							'no_match_size' => 150,
							'matched_fields' => array( 'text', 'text.plain' ),
							'fragmenter' => 'scan',
						),
						'auxiliary_text' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'experimental',
							'options' => array(
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
								'skip_if_last_matched' => true,
							),
							'matched_fields' => array( 'auxiliary_text', 'auxiliary_text.plain' ),
							'fragmenter' => 'scan',
						),
						'file_text' => array(
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'type' => 'experimental',
							'options' => array(
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
								'skip_if_last_matched' => true,
							),
							'matched_fields' => array( 'file_text', 'file_text.plain' ),
							'fragmenter' => 'scan',
						),
					),
				),
			),
			'source configuration with experimental-highlighter' => array(
				FullTextResultsType::HIGHLIGHT_ALL,
				true,
				array(
					array(
						'pattern' => '(some|thing)',
						'locale' => 'testlocale',
						'insensitive' => false,
					),
				),
				array(
					'pre_tags' => array( '<span class="searchmatch">' ),
					'post_tags' => array( '</span>' ),
					'fields' => array(
						'source_text.plain' => array(
							'type' => 'experimental',
							'number_of_fragments' => 1,
							'fragment_size' => 150,
							'options' => array(
								'regex' => array( '(some|thing)' ),
								'locale' => 'testlocale',
								'regex_flavor' => 'lucene',
								'skip_query' => true,
								'regex_case_insensitive' => false,
								'max_determinized_states' => 20000,
								'top_scoring' => true,
								'boost_before' => $boostBefore,
								'max_fragments_scored' => 5000,
							),
							'no_match_size' => 150,
							'fragmenter' => 'scan',
						),
					),
				),
			),
		);
	}
}
