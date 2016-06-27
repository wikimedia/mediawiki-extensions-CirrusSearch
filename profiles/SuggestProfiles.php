<?php

/**
 * CirrusSearch - List of profiles for search as you type suggestions
 * (Completion suggester)
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

/**
 *
 * See CirrusSearch\BuildDocument\SuggestBuilder and CirrusSearch\Searcher
 * See also: https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-completion.html
 *
 * If you add new profiles you may want to add the corresponding i18n messages with the following name:
 * cirrussearch-completion-profile-profilename
 */
$wgCirrusSearchCompletionProfiles = [
	// Strict profile (no accent squasing)
	'strict' => [
		'plain-strict' => [
			'field' => 'suggest',
			'min_query_len' => 0,
			'discount' => 1.0,
			'fetch_limit_factor' => 2,
		],
	],
	// Accent squashing and stopwords filtering
	'normal' => [
		'plain-normal' => [
			'field' => 'suggest',
			'min_query_len' => 0,
			'discount' => 1.0,
			'fetch_limit_factor' => 2,
		],
		'plain-stop-normal' => [
			'field' => 'suggest-stop',
			'min_query_len' => 0,
			'discount' => 0.001,
			'fetch_limit_factor' => 2,
		],
	],
	// Default profile
	'fuzzy' => [
		// Defines the list of suggest queries to run in the same request.
		// key is the name of the suggestion request
		'plain' => [
			// Field to request
			'field' => 'suggest',
			// Fire the request only if the user query has min_query_len chars.
			// See max_query_len to limit on max.
			'min_query_len' => 0,
			// Discount result scores for this request
			// Useful to discount fuzzy request results
			'discount' => 1.0,
			// Fetch more results than the limit
			// It's possible to have the same page multiple times
			// (title and redirect suggestion).
			// Requesting more than the limit helps to display the correct number
			// of suggestions
			'fetch_limit_factor' => 2,
		],
		'plain_stop' => [
			'field' => 'suggest-stop',
			'min_query_len' => 0,
			'discount' => 0.001,
			'fetch_limit_factor' => 2,
		],
		// Fuzzy query for query length (3 to 4) with prefix len 1
		'plain_fuzzy_2' => [
			'field' => 'suggest',
			'min_query_len' => 3,
			'max_query_len' => 4,
			'discount' => 0.000001,
			'fetch_limit_factor' => 2,
			'fuzzy' => [
				'fuzzyness' => 'AUTO',
				'prefix_length' => 1,
				'unicode_aware' => true,
			]
		],
		'plain_stop_fuzzy_2' => [
			'field' => 'suggest-stop',
			'min_query_len' => 3,
			'max_query_len' => 4,
			'discount' => 0.0000001,
			'fetch_limit_factor' => 1,
			'fuzzy' => [
				'fuzzyness' => 'AUTO',
				'prefix_length' => 2,
				'unicode_aware' => true,
			]
		],
		// Fuzzy query for query length > 5 with prefix len 0
		'plain_fuzzy_1' => [
			'field' => 'suggest',
			'min_query_len' => 5,
			'discount' => 0.000001,
			'fetch_limit_factor' => 1,
			'fuzzy' => [
				'fuzzyness' => 'AUTO',
				'prefix_length' => 1,
				'unicode_aware' => true,
			]
		],
		'plain_stop_fuzzy_1' => [
			'field' => 'suggest-stop',
			'min_query_len' => 5,
			'discount' => 0.0000001,
			'fetch_limit_factor' => 1,
			'fuzzy' => [
				'fuzzyness' => 'AUTO',
				'prefix_length' => 1,
				'unicode_aware' => true,
			]
		]
	],
];

/**
 * List of profiles for geo context suggestions
 */
$wgCirrusSearchCompletionGeoContextProfiles = [
	'default' => [
		'geo-1km' => [
			'field_suffix' => '-geo',
			// Discount applied to the score, this value will be multiplied
			// to the discount from $wgCirrusSearchCompletionProfiles
			'discount' => 1.0,
			'precision' => 6,
			// List of requests to run with this precision
			// must be a valid name from the active $wgCirrusSearchCompletionProfiles
			'with' => [ 'plain', 'plain_stop', 'plain_fuzzy', 'plain_stop_fuzzy' ]
		],
		'geo-10km' => [
			'field_suffix' => '-geo',
			'discount' => 0.5,
			'precision' => 4,
			'with' => [ 'plain', 'plain_stop', 'plain_fuzzy' ]
		],
		'geo-100km' => [
			'field_suffix' => '-geo',
			'discount' => 0.2,
			'precision' => 3,
			'with' => [ 'plain', 'plain_stop' ]
		]
	]
];
