<?php

/**
 * Sets up decently fully features cirrus configuration that relies on some of
 * the stuff installed by MediaWiki-Vagrant.
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

wfLoadExtension( 'Elastica' );

// Browser tests rely on the new vector skin layout
$wgDefaultSkin = "vector-2022";

$wgSearchType = 'CirrusSearch';
$wgCirrusSearchUseExperimentalHighlighter = true;
$wgCirrusSearchOptimizeIndexForExperimentalHighlighter = true;
$wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] = [ 'build', 'use' ];

$wgCirrusSearchQueryStringMaxDeterminizedStates = 500;
$wgCirrusSearchWikimediaExtraPlugin[ 'super_detect_noop' ] = true;
$wgCirrusSearchWikimediaExtraPlugin[ 'documentVersion' ] = true;
$wgCirrusSearchWikimediaExtraPlugin[ 'term_freq' ] = true;
$wgCirrusSearchWikimediaExtraPlugin[ 'token_count_router' ] = true;

$wgCirrusSearchNamespaceResolutionMethod = 'utr30';

// Enable when https://gerrit.wikimedia.org/r/#/c/345174/ is available
// $wgCirrusSearchWikimediaExtraPlugin[ 'token_count_router' ] = true;

$wgCirrusSearchUseCompletionSuggester = 'yes';
$wgCirrusSearchCompletionSuggesterUseDefaultSort = false;
$wgCirrusSearchCompletionSuggesterSubphrases = [
	'use' => true,
	'build' => true,
	'type' => 'anywords',
	'limit' => 10,
];

$wgCirrusSearchAlternativeIndices = [
	'completion' => [
		[
			'index_id' => 128,
			'use' => true,
			'config_overrides' => [
				'CirrusSearchCompletionSuggesterUseDefaultSort' => true,
				'CirrusSearchCompletionSuggesterSubphrases' => [
					'use' => true,
					'build' => true,
					'type' => 'subpage',
					'limit' => 10,
				],
			],
		]
	]
];

$wgCirrusSearchPhraseSuggestReverseField = [
	'build' => true,
	'use' => true,
];

// Set defaults to BM25 and the new query builder
$wgCirrusSearchSimilarityProfile = 'bm25_browser_tests';
$wgCirrusSearchFullTextQueryBuilderProfile = 'browser_tests';

$wgPoolCounterConf[ 'CirrusSearch-Search' ] = [
	'class' => 'MediaWiki\PoolCounter\PoolCounterClient',
	'timeout' => 15,
	'workers' => 432,
	'maxqueue' => 600,
];
// Super common and mostly fast
$wgPoolCounterConf[ 'CirrusSearch-Prefix' ] = [
	'class' => 'MediaWiki\PoolCounter\PoolCounterClient',
	'timeout' => 15,
	'workers' => 432,
	'maxqueue' => 600,
];
// Some classes of searches, such as Regex and deepcat, can be much heavier
// then regular searches so we limit the concurrent number.
$wgPoolCounterConf[ 'CirrusSearch-ExpensiveFullText' ] = [
	'class' => 'MediaWiki\PoolCounter\PoolCounterClient',
	'timeout' => 60,
	'workers' => 10,
	'maxqueue' => 20,
];
// These should be very very fast and reasonably rare
$wgPoolCounterConf[ 'CirrusSearch-NamespaceLookup' ] = [
	'class' => 'MediaWiki\PoolCounter\PoolCounterClient',
	'timeout' => 5,
	'workers' => 50,
	'maxqueue' => 200,
];
// Very expensive full text search. Needs to be limited separate
// from primary full text Search due to the expense.
$wgPoolCounterConf[ 'CirrusSearch-MoreLike' ] = [
	'class' => 'MediaWiki\PoolCounter\PoolCounterClient',
	'timeout' => 5,
	'workers' => 50,
	'maxqueue' => 200,
];

$wgCirrusSearchIndexDeletes = true;
$wgCirrusSearchEnableArchive = true;
$wgCirrusSearchElasticQuirks['retry_on_conflict'] = true;
$wgCirrusSearchWeightedTags = [
	'build' => true,
	'use' => true,
	'max_score' => 1000
];

$wgCirrusSearchFullTextQueryBuilderProfiles = [
	// fulltext query based on simple match queries suited for use with browser
	// tests. Not necessarily good for real world wikis.
	'browser_tests' => [
		'builder_class' => \CirrusSearch\Query\FullTextSimpleMatchQueryBuilder::class,
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
					'boost' => 1.05,
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
				'text' => 0.14,
				// higher on text.plain for tests/browser/features/relevancy_api.feature:106
				'text.plain' => 0.2,
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
		],
	],
];
