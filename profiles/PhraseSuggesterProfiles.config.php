<?php

/**
 * CirrusSearch - List of profiles for "Did you mean" suggestions
 *
 * @license GPL-2.0-or-later
 */

return [
	// This is the default settings
	'default' => [
		// The field to perform phrase suggest queries against
		'field' => 'suggest',

		// Don't suggest anything if the query has more than total_hits_threshold (set to -1 to disable)
		'total_hits_threshold' => 15000,

		// The suggest mode used by the phrase suggester
		// can be :
		// * missing: Only suggest terms in the suggest text that
		// aren’t in the index.
		// * popular: Only suggest suggestions that occur in more docs
		// then the original suggest text term.
		// * always: Suggest any matching suggestions based on terms
		// in the suggest text.
		'mode' => 'always',

		// The maximum corrections to be generated for scoring per suggest text token.
		'num_candidates' => 5,

		// Confidence level required to suggest new phrases.
		// See confidence on https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-phrase.html
		'confidence' => 2.0,

		// Maximum number of terms that we ask phrase suggest to correct.
		// See max_errors on https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-phrase.html
		'max_errors' => 2,

		// the likelihood of a term being a misspelled even if the term exists in the dictionary.
		'real_word_error_likelihood' => 0.95,

		// The max term freq used by the phrase suggester.  The maximum
		// threshold in number of documents a suggest text token can
		// exist in order to be included. Can be a relative percentage
		// number (e.g 0.4) or an absolute // number to represent
		// document frequencies. If an value higher than 1 is specified
		// then fractional can not be specified. Defaults to 0.01f.  If
		// a term appears in more then half the docs then don't try to
		// correct it.  This really shouldn't kick in much because we're
		// not looking for misspellings.  We're looking for phrases that
		// can be might off.  Like "noble prize" ->  "nobel prize".  In
		// any case, the default was 0.01 which way too frequently
		// decided not to correct some terms.
		'max_term_freq' => 0.5,

		// The max doc freq (shard level) used by the phrase suggester
		// The minimal threshold in number of documents a suggestion
		// should appear in.  This can be specified as an absolute
		// number or as a relative percentage of number of documents.
		// This can improve quality by only suggesting high frequency
		// terms. Defaults to 0f and is not enabled. If a value higher
		// than 1 is specified then the number cannot be fractional. The
		// shard level document frequencies are used for this option.
		// NOTE: this value is ignored if mode is "always"
		'min_doc_freq' => 0.0,

		// The prefix length used by the phrase suggester The number of
		// minimal prefix characters that must match in order be a
		// candidate suggestions. Defaults to 1. Increasing this number
		// improves spell check performance.  Usually misspellings don’t
		// occur in the beginning of terms.
		'prefix_length' => 2,

		// Checks each suggestion against a specified query to prune
		// suggestions for which no matching docs exist in the index.
		'collate' => false,

		// Controls the minimum_should_match option used by the collate
		// query.
		'collate_minimum_should_match' => '3<66%',

		// Smoothing model See
		// https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-phrase.html
		'smoothing_model' => [
			'stupid_backoff' => [
				'discount' => 0.4
			]
		],
	],
	// The 'strict' settings will try to avoid displaying weird suggestions.
	// (suited for small size wikis)
	'strict' => [
		'field' => 'suggest',
		// Don't suggest anything if the query has more than total_hits_threshold (set to -1 to disable)
		'total_hits_threshold' => 15000,
		'mode' => 'always',
		'num_candidates' => 5,
		'confidence' => 2.0,
		'max_errors' => 2,
		'real_word_error_likelihood' => 0.95,
		'max_term_freq' => 0.5,
		'min_doc_freq' => 0.0,
		'prefix_length' => 2,
		'collate' => true,
		'collate_minimum_should_match' => '3<66%',
		'smoothing_model' => [
			'laplace' => [
				'alpha' => 0.3
			]
		]
	],
	// Same settings as `default` but with prefix_length => 1
	'default_1' => [
		'field' => 'suggest',
		'total_hits_threshold' => 15000,
		'mode' => 'always',
		'num_candidates' => 5,
		'confidence' => 2.0,
		'max_errors' => 2,
		'real_word_error_likelihood' => 0.95,
		'max_term_freq' => 0.5,
		'min_doc_freq' => 0.0,
		'prefix_length' => 1,
		'collate' => false,
		'collate_minimum_should_match' => '3<66%',
		'smoothing_model' => [
			'stupid_backoff' => [
				'discount' => 0.4
			]
		],
	],
	// Same settings as `default` but with field => suggest_variant and prefix_length => 1
	'default_1v' => [
		'field' => 'suggest_variant',
		'total_hits_threshold' => 15000,
		'mode' => 'always',
		'num_candidates' => 5,
		'confidence' => 2.0,
		'max_errors' => 2,
		'real_word_error_likelihood' => 0.95,
		'max_term_freq' => 0.5,
		'min_doc_freq' => 0.0,
		'prefix_length' => 2,
		'collate' => false,
		'collate_minimum_should_match' => '3<66%',
		'smoothing_model' => [
			'stupid_backoff' => [
				'discount' => 0.4
			]
		],
	],
];
