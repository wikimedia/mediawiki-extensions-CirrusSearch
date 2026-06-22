<?php

use CirrusSearch\CirrusConfigNames;

/**
 * List of function score chains
 */
return [
	// Default chain where all the functions are combined
	// In the same chain.
	'classic_allinone_chain' => [
		'functions' => [
			// Scores documents with log(incoming_link + 2)
			[ 'type' => 'boostlinks' ],

			// Scores documents according to their timestamp
			// Activated if $wgCirrusSearchPreferRecentDefaultDecayPortion
			// and $wgCirrusSearchPreferRecentDefaultHalfLife are set
			// can be activated with prefer-recent special syntax
			[ 'type' => 'recency' ],

			// Scores documents according to their templates
			// Templates weights can be defined with special
			// syntax boost-templates or by setting the
			// system message cirrus-boost-templates
			[ 'type' => 'templates' ],

			// Scores documents according to their namespace.
			// Activated if the query runs on more than one namespace
			// See $wgCirrusSearchNamespaceWeights
			[ 'type' => 'namespaces' ],

			// Scores documents according to their language,
			// See $wgCirrusSearchLanguageWeight
			[ 'type' => 'language' ],
		],
		'add_extensions' => true
	],
	// Chain with optional functions if classic_allinone_chain
	// or optional_chain is omitted from the rescore profile then some
	// query features and global config will be ineffective.
	'optional_chain' => [
		'functions' => [
			[ 'type' => 'recency' ],
			[ 'type' => 'templates' ],
			[ 'type' => 'namespaces' ],
			[ 'type' => 'language' ],
		],
		'add_extensions' => true
	],
	// Chain with boostlinks only
	'boostlinks_only_chain' => [
		'functions' => [
			[ 'type' => 'boostlinks' ]
		]
	],

	// inclinks as a weighted sum
	'wsum_inclinks' => [
		'functions' => [
			[
				'type' => 'satu',
				'weight' => [
					'value' => 13,
					'config_override' => CirrusConfigNames::IncLinksAloneW,
					'uri_param_override' => 'cirrusIncLinksAloneW',
				],
				'params' => [
					'field' => 'incoming_links',
					'k' => [
						'value' => 30,
						'config_override' => CirrusConfigNames::IncLinksAloneK,
						'uri_param_override' => 'cirrusIncLinksAloneK',
					],
					'a' => [
						'value' => 0.7,
						'config_override' => CirrusConfigNames::IncLinksAloneA,
						'uri_param_override' => 'cirrusIncLinksAloneA',
					]
				],
			],
		],
	],

	// inclinks as a weighted sum
	'wsum_inclinks_pv' => [
		'score_mode' => 'sum',
		'boost_mode' => 'sum',
		'functions' => [
			[
				'type' => 'satu',
				'weight' => [
					'value' => 3,
					'config_override' => CirrusConfigNames::PageViewsW,
					'uri_param_override' => 'cirrusPageViewsW',
				],
				'params' => [
					'field' => 'popularity_score',
					'k' => [
						'value' => 8E-6,
						'config_override' => CirrusConfigNames::PageViewsK,
						'uri_param_override' => 'cirrusPageViewsK',
					],
					'a' => [
						'value' => 0.8,
						'config_override' => CirrusConfigNames::PageViewsA,
						'uri_param_override' => 'cirrusPageViewsA',
					],
				],
			],
			[
				'type' => 'satu',
				'weight' => [
					'value' => 10,
					'config_override' => CirrusConfigNames::IncLinksW,
					'uri_param_override' => 'cirrusIncLinksW',
				],
				'params' => [
					'field' => 'incoming_links',
					'k' => [
						'value' => 30,
						'config_override' => CirrusConfigNames::IncLinksK,
						'uri_param_override' => 'cirrusIncLinksK',
					],
					'a' => [
						'value' => 0.7,
						'config_override' => CirrusConfigNames::IncLinksA,
						'uri_param_override' => 'cirrusIncLinksA',
					],
				],
			],
		],
	],

	// like wsum_inclinks_pv, but heavily weighted towards the popularity score
	'wsum_inclinks_pv+' => [
		'score_mode' => 'sum',
		'boost_mode' => 'sum',
		'functions' => [
			[
				'type' => 'satu',
				'weight' => [
					'value' => 1000,
					'config_override' => CirrusConfigNames::PageViewsW,
					'uri_param_override' => 'cirrusPageViewsW',
				],
				'params' => [
					'field' => 'popularity_score',
					'k' => [
						'value' => 8E-6,
						'config_override' => CirrusConfigNames::PageViewsK,
						'uri_param_override' => 'cirrusPageViewsK',
					],
					'a' => [
						'value' => 0.8,
						'config_override' => CirrusConfigNames::PageViewsA,
						'uri_param_override' => 'cirrusPageViewsA',
					],
				],
			],
			[
				'type' => 'satu',
				'weight' => [
					'value' => 10,
					'config_override' => CirrusConfigNames::IncLinksW,
					'uri_param_override' => 'cirrusIncLinksW',
				],
				'params' => [
					'field' => 'incoming_links',
					'k' => [
						'value' => 30,
						'config_override' => CirrusConfigNames::IncLinksK,
						'uri_param_override' => 'cirrusIncLinksK',
					],
					'a' => [
						'value' => 0.7,
						'config_override' => CirrusConfigNames::IncLinksA,
						'uri_param_override' => 'cirrusIncLinksA',
					],
				],
			],
		],
	],
];
