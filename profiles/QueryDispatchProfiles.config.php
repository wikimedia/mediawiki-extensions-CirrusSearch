<?php

/**
 * CirrusSearch - List of query dispatch profiles
 *
 * A profile is the route table for fulltext search. It decides which named
 * query context we will use. Picking a profile is how a wiki says
 * which queries get which retrieval strategy. Currently only two retrieval
 * strategies exist, lexical and semantic search.
 *
 * Order matters. A route stops at the first veto or force. Voters may
 * talk to the search backend, so the cheap voters belong first. Moving the
 * debug option voter ahead of the gates would let the debug option override
 * them, which is not what the gates are for.
 *
 * Score decides between multiple routes when more than one is accepted. The
 * score is strictly for ranking the winners. Two routes that both accept and
 * both score 1.0 are an error.
 *
 * Every profile names a default route, which is where a query goes when no
 * route accepted it.  That route is not in the election, so it has neither
 * voters nor a score. It is also what a caller that must not dispatch at all
 * latches onto, as cross-wiki search does.
 *
 * @license GPL-2.0-or-later
 */

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\Dispatch\Voter\NamespaceVetoVoter;
use CirrusSearch\Dispatch\Voter\QueryClassVetoVoter;
use CirrusSearch\Dispatch\Voter\QueryLengthCandidateVoter;
use CirrusSearch\Dispatch\Voter\SemanticDebugOptionForceVoter;
use CirrusSearch\Dispatch\Voter\TitleMatchVetoVoter;
use CirrusSearch\Parser\BasicQueryClassifier;
use CirrusSearch\Profile\SearchProfileService;

// Sends a query of at least $wgCirrusSearchSemanticQueryLengthThreshold tokens to the
// semantic route. Shared by the profiles that gate on query length.
$queryLength = [
	'class' => QueryLengthCandidateVoter::class,
	'params' => [
		QueryLengthCandidateVoter::PARAM_THRESHOLD_CONFIG => CirrusConfigNames::SemanticQueryLengthThreshold,
	],
];

// Where a query goes when no route accepted it. Named by every profile below, so there is
// always somewhere to send a query no other route wanted.
$cirrusDefault = [
	'context' => SearchProfileService::CONTEXT_DEFAULT,
];

/**
 * The semantic route, with whichever signals propose it.
 *
 * Sets up the default limits on namespaces and query class, along with the voter that
 * recognizes the debug option.
 *
 * @param array $proposedBy extra voters, added to the end of the voters list.
 * @return array
 */
$semantic = static function ( array $proposedBy = [] ) {
	return [
		'context' => SearchProfileService::CONTEXT_SEMANTIC,
		'score' => 1.0,
		// No semantic profile means nothing to retrieve with, so the route is left out
		// entirely rather than built and vetoed on every query.
		'requires' => CirrusConfigNames::DefaultSemanticProfile,
		'voters' => [
			// semantic only holds NS_MAIN content
			'namespaces' => [
				'class' => NamespaceVetoVoter::class,
				'params' => [ NamespaceVetoVoter::PARAM_NAMESPACES => [ NS_MAIN ] ],
			],
			// semantic doesn't do special handling for quotes or keywords,
			// it only works on a bag of words.
			'query_classes' => [
				'class' => QueryClassVetoVoter::class,
				'params' => [
					QueryClassVetoVoter::PARAM_CLASSES => [ BasicQueryClassifier::SIMPLE_BAG_OF_WORDS ],
				],
			],
			'debug_option' => [ 'class' => SemanticDebugOptionForceVoter::class ],
		] + $proposedBy,
	];
};

return [
	// No query reaches semantic retrieval on its own. Where a wiki starts.
	'default' => [
		'default_route' => 'cirrus_default',
		'routes' => [
			'cirrus_default' => $cirrusDefault,
			'semantic' => $semantic(),
		],
	],

	// Queries of at least $wgCirrusSearchSemanticQueryLengthThreshold tokens go to semantic
	// retrieval.
	'semantic_by_query_length' => [
		'default_route' => 'cirrus_default',
		'routes' => [
			'cirrus_default' => $cirrusDefault,
			'semantic' => $semantic( [
				'query_length' => $queryLength,
			] ),
		],
	],

	// As semantic_by_query_length, except that a query naming a page that exists stays
	// lexical. This costs one extra near-match request to the search backend per query, before
	// the main query can be built. See $wgCirrusSearchQueryDispatchProfile in settings.txt.
	'semantic_by_query_length_unless_title_match' => [
		'default_route' => 'cirrus_default',
		'routes' => [
			'cirrus_default' => $cirrusDefault,
			'semantic' => $semantic( [
				'query_length' => $queryLength,
				'title_match' => [ 'class' => TitleMatchVetoVoter::class ],
			] ),
		],
	],
];
