<?php

return [
	'default' => [
		'strategies' => [
			// the key is the strategy name from SecondTryLanguageFactory
			// The value is an array or a float denoting the weight
			'language_converter' => [
				// weight used to prioritize the various strategies
				'weight' => 1.0,
				// strategy specific settings
				'settings' => [
					'top_k' => 3
				]
			]
		]
	],
	'language_converter_and_russian_wrong_keyboard' => [
		'strategies' => [
			'language_converter' => 1.0,
			'russian_keyboard' => 0.5
		],
	],
	'language_converter_and_russian_wrong_keyboard_cyr2lat' => [
		'strategies' => [
			'language_converter' => 1.0,
			'russian_keyboard' => [
				'weight' => 0.5,
				'settings' => [
					'dir' => 'c2l'
				]
			]
		],
	],
	'language_converter_and_hebrew_wrong_keyboard' => [
		'strategies' => [
			'language_converter' => 1.0,
			'hebrew_keyboard' => 0.5
		],
	],
	'language_converter_and_georgian_transliteration' => [
		'strategies' => [
			'language_converter' => 1.0,
			'georgian_transliteration' => 0.5
		],
	]
];
