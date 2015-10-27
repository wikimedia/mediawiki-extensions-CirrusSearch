<?php

namespace CirrusSearch;
/**
 * CirrusSearch - List of profiles for CommonsTermQuery
 * see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-common-terms-query.html
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

$wgCirrusSearchCommonTermsQueryProfiles = array(
	// This is the default profile
	'default' => array(
		// Minimal number of required terms in the query
		// to activate the CommonTermsQuery
		'min_query_terms' => 4,
		// The cut off frequency in % (per shard) used to split terms
		// into high freq (common words) and low freq groups (salient
		// words).
		'cutoff_freq' => 0.001,
		// minimum_should_match operator to use for each group
		// see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-minimum-should-match.html
		// 0 required for 1, and 50% required for 2 or more
		'high_freq_min_should_match' => '0<0 1<50%',
		// If there are more that 5 salient terms 90% of them must match
		// all otherwise
		'low_freq_min_should_match' => '5<90%',
		'stems_clause' => array(
			// Configuration for language specific field
			// Which generally exclude stopwords
			'use_common_terms' => true,
			'cutoff_freq' => 0.05,
			// 0 out of 1, or 50% out of 2 or more
			'high_freq_min_should_match' => '0<0 1<50%',
			'low_freq_min_should_match' => '100%'
		),
	),
	'strict' => array(
		'min_query_terms' => 6,
		'cutoff_freq' => 0.001,
		// 0 out of 1, or 75% out of 2 or more
		'high_freq_min_should_match' => '0<0 1<75%',
		'low_freq_min_should_match' => '100%',
		'stems_clause' => array(
			// We do not use the common terms query here
			// Just a simple match query
			'use_common_terms' => false,
			// All terms are required
			'min_should_match' => '100%'
		),
	),
	'aggressive_recall' => array(
		'min_query_terms' => 3,
		'cutoff_freq' => 0.0006,
		// 0 out of 1-2, or 25% out of 3 or more
		'high_freq_min_should_match' => '0<0 2<25%',
		// 2 out of 2, or 66% out of 3 or more
		'low_freq_min_should_match' => '2<66%',
		'stems_clause' => array(
			'use_common_terms' => true,
			'cutoff_freq' => 0.001,
			// 0 out of 1-2, or 25% out of 3 or more
			'high_freq_min_should_match' => '0<0 2<50%',
			// 3 out of 3, or 80% out of 4 or more
			'low_freq_min_should_match' => '3<80%'
		),
	)
);

class CommonTermsQueryProfiles {
	public static function overrideOptions( $request ) {
		global $wgCirrusSearchUseCommonTermsQuery,
			$wgCirrusSearchCommonTermsQueryProfile,
			$wgCirrusSearchCommonTermsQueryProfiles;

		Util::overrideYesNo( $wgCirrusSearchUseCommonTermsQuery, $request, 'cirrusUseCommonTermsQuery' );
		if ( $wgCirrusSearchUseCommonTermsQuery ) {
			$profile = $request->getVal( 'cirrusCommonTermsQueryProfile' );
			if ( $profile !== null && isset ( $wgCirrusSearchCommonTermsQueryProfiles[$profile] ) ) {
				$wgCirrusSearchCommonTermsQueryProfile = $wgCirrusSearchCommonTermsQueryProfiles[$profile];
			}
		}
	}
}
