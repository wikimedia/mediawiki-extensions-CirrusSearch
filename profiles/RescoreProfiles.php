<?php

namespace CirrusSearch;

use WebRequest;

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
 *
 */

$wgCirrusSearchRescoreProfiles = array(
	// Default profile which uses an all in one function score chain
	'classic' => array(
		// i18n description for this profile.
		'i18n_msg' => 'cirrussearch-qi-profile-classic',
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

				// The window size can be overridden by a config a value if set
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
				'function_chain' => 'classic_allinone_chain'
			)
		)
	),

	// Default rescore without boostlinks
	'classic_noboostlinks' => array(
		'i18n_msg' => 'cirrussearch-qi-profile-classic-noboostlinks',
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

	// Useful to debug primary lucene score
	'empty' => array(
		'i18n_msg' => 'cirrussearch-qi-profile-empty',
		'supported_namespaces' => 'all',
		'rescore' => array(),
	),
);

/**
 * List of function score chains
 */
$wgCirrusSearchRescoreFunctionScoreChains = array(
	// Default chain where all the functions are combined
	// In the same chain.
	'classic_allinone_chain' => array(
		'functions' => array(
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

			// Boosts documents in a particular geographic area.
			// Triggered by query syntax.
			array( 'type' => 'georadius', 'weight' => array(
				'value' => 2,
				'config_override' => 'CirrusSearchPreferGeoRadiusWeight',
				'uri_param_override' => 'cirrusPreferGeoRadiusWeight',
			) ),
		)
	),
	// Chain with optional functions if classic_allinone_chain
	// or optional_chain is omitted from the rescore profile then some
	// query features and global config will be ineffective.
	'optional_chain' => array(
		'functions' => array(
			array( 'type' => 'recency' ),
			array( 'type' => 'templates' ),
			array( 'type' => 'namespaces' ),
			array( 'type' => 'language' ),
			array( 'type' => 'georadius', 'weight' => array(
				'value' => 2,
				'config_override' => 'CirrusSearchPreferGeoRadiusWeight',
				'uri_param_override' => 'cirrusPreferGeoRadiusWeight',
			) ),
		)
	),
	// Chain with boostlinks only
	'boostlinks_only_chain' => array(
		'functions' => array(
			array( 'type' => 'boostlinks' )
		)
	),

//	// Example chain (do not use) with incoming_links to illustrate
//	// the 'custom_field' function score type.
//	// Simulates the behavior of boostlinks by using a custom field.
//	'custom_incominglinks' => array(
//		// First, each document is scored by the defined functions. The
//		// parameter score_mode specifies how the computed scores are
//		// combined. Makes sense only if more than one function are added
//		// to the chain.
//		'boost_mode' => 'multiply',
//		'functions' => array(
//			array(
//				// custom field allows you to use a custom numeric
//				// field with a field_value_factor function score.
//				'type' => 'custom_field',
//				// If multiple functions are added to the chain
//				// weight is a factor applied to the function result.
//				// If the function produces more than one function
//				// (templates, namespaces, language) then this weight
//				// is multiplied to the weight computed by the function
//				'weight' => 1,
//
//				// Params used by field_value_factor
//				// see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-function-score-query.html#function-field-value-factor
//				'params' => array(
//					// field name
//					'field' => 'incoming_links',
//
//					// Optional factor to multiply the field value
//					// with, defaults to 1.
//					'factor' => array(
//						'value' => 1,
//						'config_override' => 'CirrusSearchBoostLinksFactor',
//						'uri_param_override' => 'cirrusBoostLinksFactor',
//					),
//
//					// Modifier to apply to the field value, can be
//					// one of: none, log, log1p, log2p, ln, ln1p,
//					// ln2p, square, sqrt, or reciprocal. Defaults
//					// to none.
//					'modifier' => 'log2p',
//
//					// Value used if the document doesn’t have that
//					// field. The modifier and factor are still
//					// applied to it as though it were read from
//					// the document.
//					'missing' => 0,
//				),
//			),
//			array(
//				// Log scale boost,
//				// Generates a boost factor (min: 1, max: impact)
//				'type' => 'logscale_boost',
//				'params' => array(
//					'field' => 'popularity_score',
//					// Scale, usually set to the max value
//					'scale' => array(
//						'value' => 0.0004,
//						'uri_param_override' => 'cirrusBoostLinksScale',
//						'config_override' => 'CirrusSearchBoostLinksScale',
//					),
//					// Set the midpoint point where this function generates
//					// so that a field value of 'midpoint' is at the center
//					// of the scale
//					'midpoint' => array(
//						'value' => 0.0000005,
//						'uri_param_override' => 'cirrusBoostLinksCenter',
//						'config_override' => 'CirrusSearchBoostLinksCenter',
//					),
//					// Set the impact, a value of one can double the score
//					'impact' => array(
//						'value' => 1,
//						'uri_param_override' => 'cirrusBoostLinksImpact',
//						'config_override' => 'CirrusSearchBoostLinksImpact',
//					),
//				)
//		),
//	),
//	// Example chain (do not use) with incoming_links to illustrate
//	// the 'script' function score type.
//	// Simulates the behavior of boostlinks by using a script.
//	'custom_incominglinks_script' => array(
//		'functions' => array(
//			array(
//				'type' => 'script',
//				'script' => "log10( doc['incoming_links'].value + 2)"
//			),
//		),
//	),
);

/**
 * Utility class to override the default rescore profile at runtime.
 * Used by includes/Hooks.php
 */
class RescoreProfiles {
	/**
	 * @param WebRequest $request
	 */
	public static function overrideOptions( WebRequest $request ) {
		global $wgCirrusSearchRescoreProfile,
			$wgCirrusSearchRescoreProfiles;

		$profile = $request->getVal( 'cirrusRescoreProfile' );
		if ( $profile !== null && isset ( $wgCirrusSearchRescoreProfiles[$profile] ) ) {
			$wgCirrusSearchRescoreProfile = $profile;
		}
	}
}
