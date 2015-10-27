<?php

namespace CirrusSearch;
/**
 * CirrusSearch - List of profiles for function score rescores.
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
 * List of rescore profiles.
 *
 * NOTE: writing a new custom profile is a complex task, you can use
 * &cirrusDumpResult&cirrusExplain query params to dump score information at
 * runtime.
 */
$wgCirrusSearchRescoreProfiles = array(
	// Default profile which uses an all in one function score chain
	'default' => array(
		// use 'all' if this rescore profile supports all namespaces
		// or an array of integer to limit
		'supported_namespaces' => 'all',

		// If the profile does not support all namespaces
		// you must provide a fallback profile that supports
		// all. It will be use with queries applied to namespace
		// not supported by this profile :
		// 'fallback_profile' => 'profile',

		// List of rescores
		// https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-rescore.html
		'rescore' => array(
			array(
				// the rescore window size
				'window' => 8192,

				// The window size can be overiden by a config a value if set
				'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',

				// relative importance of the original query
				'query_weight' => 1.0,

				// relative importance of the rescore query
				'rescore_query_weight' => 1.0,

				// how to combine query and rescore scores
				// can be total, multiply, avg, max or min
				'score_mode' => 'multiply',

				// type of the rescore query
				// (only supports function_score for now)
				'type' => 'function_score',

				// name of the function score chains, must be
				// defined in $wgCirrusSearchRescoreFunctionScoreChains
				'function_chain' => 'default_allinone_chain'
			)
		)
	),

	// Default rescore without boostlinks
	'default_noboostlinks' => array(
		'supported_namespaces' => 'all',
		'rescore' => array(
			array(
				'window' => 8192,
				'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
				'query_weight' => 1.0,
				'rescore_query_weight' => 1.0,
				'score_mode' => 'multiply',
				'type' => 'function_score',
				'function_chain' => 'optional_chain'
			),
		)
	),

	// Rescore profile where boostlinks is included in a separate window
	// and overboosted with a different weight
	'overboostlinks' => array(
		'supported_namespaces' => 'all',
		'rescore' => array(
			array(
				'window' => 8192,
				'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
				'query_weight' => 1.0,
				'rescore_query_weight' => 1.0,
				'score_mode' => 'multiply',
				'type' => 'function_score',
				'function_chain' => 'optional_chain'
			),
			array(
				'window' => 8192,
				'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
				'query_weight' => 0.7,
				'rescore_query_weight' => 1.3,
				'score_mode' => 'multiply',
				'type' => 'function_score',
				'function_chain' => 'boostlinks_only'
			)
		)
	),

	// Same as overboostlinks but with a lower weight for boostlinks
	'underboostlinks' => array(
		'supported_namespaces' => 'all',
		'rescore' => array(
			array(
				'window' => 8192,
				'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
				'query_weight' => 1.0,
				'rescore_query_weight' => 1.0,
				'score_mode' => 'multiply',
				'type' => 'function_score',
				'function_chain' => 'optional_chain'
			),
			array(
				'window' => 8192,
				'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
				'query_weight' => 1.5,
				'rescore_query_weight' => 0.2,
				'score_mode' => 'multiply',
				'type' => 'function_score',
				'function_chain' => 'boostlinks_only'
			)
		)
	),


	// Example profile with custom_field: do not use.
	// Documents will be ordered from less to most relevant
	'negativeboostlinks' => array(
		// Supports only the main namespace
		'supported_namespaces' => array( 0 ),

		// Fallbacks to default
		'fallback_profile' => 'default',

		'rescore' => array(
			// Always include a rescore with optional_chain
			// otherwize some query special syntax will be
			// ineffective.
			array(
				'window' => 8192,
				'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
				'query_weight' => 1.0,
				'rescore_query_weight' => 1.0,
				'score_mode' => 'multiply',
				'type' => 'function_score',
				'function_chain' => 'optional_chain'
			),
			array(
				'window' => 8192,
				'query_weight' => 0,
				'rescore_query_weight' => -2.0,
				'score_mode' => 'total',
				'type' => 'function_score',
				'function_chain' => 'custom_incominglinks'
			)
		)
	)
);

/**
 * List of function score chains
 */
$wgCirrusSearchRescoreFunctionScoreChains = array(
	// Default chain where all the functions are combined
	// In the same chain.
	'default_allinone_chain' => array(
		// Scores documents with log(incoming_link + 2)
		// Activated if $wgCirrusSearchBoostLinks is set
		array( 'type' => 'boostlinks' ),

		// Scores documents according to their timestamp
		// Activated if $wgCirrusSearchPreferRecentDefaultDecayPortion
		// and $wgCirrusSearchPreferRecentDefaultHalfLife are set
		// can be activated with prefer-recent special syntax
		array( 'type' => 'recency' ),

		// Scores documents according to their templates
		// Templates weights can be defined with special
		// syntax boost-templates or by setting the
		// system message cirrus-boost-templates
		array( 'type' => 'templates' ),

		// Scores documents according to their namespace.
		// Activated if the query runs on more than one namespace
		// See $wgCirrusSearchNamespaceWeights
		array( 'type' => 'namespaces' ),

		// Scores documents according to their language,
		// See $wgCirrusSearchLanguageWeight
		array( 'type' => 'language' ),
	),

	// Chain with optionnal functions
	'optional_chain' => array(
		array( 'type' => 'recency' ),
		array( 'type' => 'templates' ),
		array( 'type' => 'namespaces' ),
		array( 'type' => 'language' ),
	),

	// Chain with boostlinks only
	'boostlinks_only' => array(
		array( 'type' => 'boostlinks' )
	),

	// Example chain (do not use) with incoming_links to illustrate
	// the 'custom_field' function score type.
	// Simulates the behavior of boostlinks by using a custom field.
	'custom_incominglinks' => array(
		array(
			// custom field allows you to use a custom numeric
			// field with a field_value_factor function score.
			'type' => 'custom_field',

			// Params used by field_value_factor
			// see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-function-score-query.html#function-field-value-factor
			'params' => array(
				// field name
				'field' => 'incoming_links',

				// Optional factor to multiply the field value
				// with, defaults to 1.
				'factor' => 1,

				// Modifier to apply to the field value, can be
				// one of: none, log, log1p, log2p, ln, ln1p,
				// ln2p, square, sqrt, or reciprocal. Defaults
				// to none.
				'modifier' => 'log2p',

				// Value used if the document doesn’t have that
				// field. The modifier and factor are still
				// applied to it as though it were read from
				// the document.
				'missing' => 0,
			)
		),
	),
);

/**
 * Utility class to override the default rescore profile at runtime.
 * Used by includes/Hooks.php
 */
class RescoreProfiles {
	public static function overrideOptions( $request ) {
		global $wgCirrusSearchRescoreProfile,
			$wgCirrusSearchRescoreProfiles;

		$profile = $request->getVal( 'cirrusRescoreProfile' );
		if ( $profile !== null && isset ( $wgCirrusSearchRescoreProfiles[$profile] ) ) {
			$wgCirrusSearchRescoreProfile = $wgCirrusSearchRescoreProfiles[$profile];
		}
	}
}
