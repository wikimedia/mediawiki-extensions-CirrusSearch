<?php

/**
 * CirrusSearch - List of profiles for "fallback" methods
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

$profiles = [
	'none' => []
];

foreach ( [
	'default', 'strict', 'expensive_1', 'expensive_2',
	'variant', 'expensive_1_variant', 'expensive_2_variant'
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
