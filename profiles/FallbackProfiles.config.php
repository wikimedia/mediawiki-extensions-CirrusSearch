<?php

/**
 * CirrusSearch - List of profiles for "fallback" methods
 *
 * @license GPL-2.0-or-later
 */

$profiles = [
	'none' => []
];

foreach ( [
	'default', 'strict', 'default_1', 'default_1v',
] as $profileName ) {
	$suffix = $profileName === 'default' ? '' : "_{$profileName}";
	$profiles["phrase_suggest{$suffix}"] = [
		'methods' => [
			'phrase-default' => [
				'class' => \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod::class,
				'params' => [
					'profile' => $profileName,
				]
			],
		],
	];
	$profiles["phrase_suggest{$suffix}_and_language_detection"] = [
		'methods' => [
			'phrase-default' => [
				'class' => \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod::class,
				'params' => [
					'profile' => $profileName,
				]
			],
			'langdetect' => [
				'class' => \CirrusSearch\Fallbacks\LangDetectFallbackMethod::class,
				'params' => []
			],
		]
	];
	$profiles["phrase_suggest{$suffix}_glentM01_and_langdetect"] = [
		'methods' => [
			'glent-m01run' => [
				'class' => \CirrusSearch\Fallbacks\IndexLookupFallbackMethod::class,
				// Glent is trained on queries from the default namespace, do not enable it
				// when the target namespaces to search on is not the same.
				'default_ns_only' => true,
				'params' => [
					'profile' => 'glent',
					'profile_params' => [
						'methods' => [ 'm0run', 'm1run' ],
					]
				]
			],
			'phrase-default' => [
				'class' => \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod::class,
				'params' => [
					'profile' => $profileName,
				]
			],
			'langdetect' => [
				'class' => \CirrusSearch\Fallbacks\LangDetectFallbackMethod::class,
				'params' => []
			],
		],
	];
}

return $profiles;
