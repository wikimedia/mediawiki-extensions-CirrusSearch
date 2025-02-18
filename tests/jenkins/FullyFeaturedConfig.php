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
$wgCirrusSearchCompletionSuggesterUseDefaultSort = true;
$wgCirrusSearchCompletionSuggesterSubphrases = [
	'use' => true,
	'build' => true,
	'type' => 'anywords',
	'limit' => 10,
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
$wgCirrusSearchWMFExtraFeatures = [
	'weighted_tags' => [
		'build' => true,
		'run' => true,
	]
];
