<?php
/**
 * Profiles for NamespaceMatcher.
 * Accepts two keys: index_second_try_profile and search_second_try_profile whose values must
 * exist in the set of SecondTry profiles.
 * The index part is used to normalized available namespaces, the search part is used to normalize
 * the query before trying to match one of the normalized namespace.
 */
return [
	'naive' => [
		'index_second_try_profile' => 'icu_folding_naive',
		'search_second_try_profile' => 'icu_folding_naive',
	],
	'utr30' => [
		'index_second_try_profile' => 'icu_folding_utr30',
		'search_second_try_profile' => 'icu_folding_utr30',
	],
	'utr30_with_hebrew_wrong_keyboard' => [
		'index_second_try_profile' => 'icu_folding_utr30',
		'search_second_try_profile' => 'utr30_and_hebrew_wrong_keyboard',
	]
];
