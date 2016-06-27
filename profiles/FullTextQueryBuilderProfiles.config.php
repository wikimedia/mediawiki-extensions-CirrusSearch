<?php

namespace CirrusSearch;

use WebRequest;

/**
 * CirrusSearch - List of FullTextQueryBuilderProfiles used to generate an elasticsearch
 * query by parsing user input.
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

$wgCirrusSearchFullTextQueryBuilderProfiles = [
	// default query builder, based on top of QueryString
	'default' => [
		'builder_class' => Query\FullTextQueryStringQueryBuilder::class,
		'settings' => [],
	],
	// fulltext query based on simple match queries suited to with browser tests
	// Not necessarily good for real world wikis
	'browser_tests' => [
		'builder_class' => Query\FullTextSimpleMatchQueryBuilder::class,
		// Adjusted according to tests/browser/features/relevancy_api.feature
		// and a fresh index (no deletes) and bm25 defaults for all fields
		// title > redirects > category > heading > opening > text > aux
		// These settings might not be ideal with a real index and real word norms
		'settings' => [
			'default_min_should_match' => '1',
			'default_query_type' => 'most_fields',
			'default_stem_weight' => 0.3,
			'fields' => [
				// very high title weight for features/create_new_page.feature:23
				// Make sure that Catapult wins Catapult/adsf despite not having
				// Catapult in the content
				'title' => 2.3,
				'redirect.title' => [
					'boost' => 2.0,
					'in_dismax' => 'redirects_or_shingles'
				],
				// Shingles on title+redirect, suggest is
				// currently analyzed only with plain so we
				// include them in a dismax with redirects
				'suggest' => [
					'is_plain' => true,
					'boost' => 2.1,
					'in_dismax' => 'redirects_or_shingles',
				],
				// category should win over heading/opening
				'category' => 1.8,
				'heading' => 1.3,
				// Pack text and opening_text in a dismax query
				// this is to avoid scoring twice the same words
				'text' => [
					'boost' => 0.4,
					'in_dismax' => 'text_and_opening_text',
				],
				'opening_text' => [
					'boost' => 0.5,
					'in_dismax' => 'text_and_opening_text',
				],
				'auxiliary_text' => 0.2,
				'file_text' => 0.2,
			],
			'phrase_rescore_fields' => [
				// Low boost to counter high phrase rescore boost
				'text' => 0.07,
				// higher on text.plain for tests/browser/features/relevancy_api.feature:106
				'text.plain' => 0.1,
			],
			'dismax_settings' => [
				// Use a tie breaker, avg field length is so
				// low for opening_text that we would have to
				// set an insanely high boost to make sure it
				// wins text in the dismax. Instead we use a
				// tie breaker that will add 20% of the score
				// of the opening_text clauses
				'text_and_opening_text' => [
					'tie_breaker' => 0.2,
				],
			],
		]
	],
];
