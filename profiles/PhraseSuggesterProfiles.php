<?php

namespace CirrusSearch;
/**
 * CirrusSearch - List of profiles for "Did you mean" suggestions
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

$wgCirrusSearchPhraseSuggestProfiles = array(
	// This is the default settings
	'default' => array(
		// The suggest mode used by the phrase suggester
		// can be :
		//  * missing: Only suggest terms in the suggest text that
		//             aren’t in the index.
		//  * popular: Only suggest suggestions that occur in more docs
		//             then the original suggest text term.
		//  * always: Suggest any matching suggestions based on terms
		//            in the suggest text.
		'mode' => 'always',

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
		// improves spellcheck performance.  Usually misspellings don’t
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
		'smoothing_model' => array(
			'stupid_backoff' => array(
				'discount' => 0.4
			)
		),
	),
	// The 'strict' settings will try to avoid displaying weird suggestions.
	// (suited for small size wikis)
	'strict' => array(
		'mode' => 'always',
		'confidence' => 2.0,
		'max_errors' => 2,
		'real_word_error_likelihood' => 0.95,
		'max_term_freq' => 0.5,
		'min_doc_freq' => 0.0,
		'prefix_length' => 2,
		'collate' => true,
		'collate_minimum_should_match' => '3<66%',
		'smoothing_model' => array(
			'laplace' => array(
				'alpha' => 0.3
			)
		)
	),
	// Alternative settings, confidence set to 1 but with laplace smoothing
	'alternative' => array(
		'mode' => 'always',
		'confidence' => 1.0,
		'max_errors' => 2,
		'real_word_error_likelihood' => 0.95,
		'max_term_freq' => 0.5,
		'min_doc_freq' => 0.0,
		'prefix_length' => 2,
		'collate' => false,
		'collate_minimum_should_match' => '3<66%',
		'smoothing_model' => array(
			'laplace' => array(
				'alpha' => 0.3
			)
		)
	)
);

class PhraseSuggesterProfiles {
	/**
	 * Override Phrase suggester options ("Did you mean?" suggestions)
	 */
	public static function overrideOptions( $request ) {
		global $wgCirrusSearchPhraseSuggestMaxErrors,
			$wgCirrusSearchPhraseSuggestConfidence,
			$wgCirrusSearchPhraseSuggestSettings,
			$wgCirrusSearchPhraseSuggestMaxTermFreqHardLimit,
			$wgCirrusSearchPhraseSuggestMaxErrorsHardLimit,
			$wgCirrusSearchPhraseSuggestPrefixLengthHardLimit,
			$wgCirrusSearchPhraseSuggestAllowedMode,
			$wgCirrusSearchPhraseSuggestAllowedSmoothingModel;

		Util::overrideNumeric( $wgCirrusSearchPhraseSuggestSettings['max_errors'], $request,
			'cirrusSuggMaxErrors', $wgCirrusSearchPhraseSuggestMaxErrorsHardLimit );
		Util::overrideNumeric( $wgCirrusSearchPhraseSuggestSettings['confidence'], $request,
			'cirrusSuggConfidence');
		Util::overrideNumeric( $wgCirrusSearchPhraseSuggestSettings['max_term_freq'], $request,
			'cirrusSuggMaxTermFreq', $wgCirrusSearchPhraseSuggestMaxTermFreqHardLimit );
		Util::overrideNumeric( $wgCirrusSearchPhraseSuggestSettings['min_doc_freq'], $request,
			'cirrusSuggMinDocFreq' );
		Util::overrideNumeric( $wgCirrusSearchPhraseSuggestSettings['prefix_length'], $request,
			'cirrusSuggPrefixLength', $wgCirrusSearchPhraseSuggestPrefixLengthHardLimit, false );
		$mode = $request->getVal( 'cirrusSuggMode' );
		if( isset ( $mode ) && in_array( $mode, $wgCirrusSearchPhraseSuggestAllowedMode ) ) {
			$wgCirrusSearchPhraseSuggestSettings['mode'] = $mode;
		}

		// NOTE: we do not allow collate_minimum_should_match to be customized, it'd be hard to parse.
		Util::overrideYesNo( $wgCirrusSearchPhraseSuggestSettings['collate'], $request, 'cirrusSuggCollate' );

		$smoothing = $request->getVal( 'cirrusSuggSmoothing' );
		if ( isset ( $smoothing ) && in_array( $smoothing, $wgCirrusSearchPhraseSuggestAllowedSmoothingModel ) ) {
			// We do not support linear_interpolation customization yet, should be added
			// later if proven useful.
			switch ( $smoothing ) {
			case 'laplace' :
				$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = array(
					'laplace' => array(
						'alpha' => 0.5
					)
				);
				break;
			case 'stupid_backoff' :
				$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = array(
					'stupid_backoff' => array(
						'discount' => 0.4
					)
				);
				break;
			}
		}

		// Custom discount for stupid_backoff smoothing model
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff'] ) ) {
			$discount = $request->getVal('cirrusSuggDiscount');
			if( is_numeric( $discount ) && $discount <= 1 && $discount >= 0 ) {
				$wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff']['discount'] = floatval( $discount );
			}
		}

		// Custom alpha for laplace smoothing model
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace'] ) ) {
			$alpha = $request->getVal('cirrusSuggAlpha');
			if( is_numeric( $alpha ) && $alpha <= 1 && $alpha >= 0 ) {
				$wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace']['alpha'] = floatval( $alpha );
			}
		}

		// Support deprecated settings
		if ( isset ( $wgCirrusSearchPhraseSuggestConfidence ) ) {
			Util::overrideNumeric( $wgCirrusSearchPhraseSuggestConfidence, $request, 'cirrusSuggConfidence' );
		}
		if ( isset ( $wgCirrusSearchPhraseSuggestMaxErrors ) ) {
			Util::overrideNumeric( $wgCirrusSearchPhraseSuggestMaxErrors, $request, 'cirrusSuggMaxErrors',
				$wgCirrusSearchPhraseSuggestMaxErrorsHardLimit );
		}
	}

	/**
	 * Override Phrase suggester options ("Did you mean?" suggestions)
	 */
	public static function overrideOptionsFromMessage( ) {
		global $wgCirrusSearchPhraseSuggestMaxErrors,
			$wgCirrusSearchPhraseSuggestConfidence,
			$wgCirrusSearchPhraseSuggestSettings,
			$wgCirrusSearchPhraseSuggestMaxTermFreqHardLimit,
			$wgCirrusSearchPhraseSuggestMaxErrorsHardLimit,
			$wgCirrusSearchPhraseSuggestPrefixLengthHardLimit,
			$wgCirrusSearchPhraseSuggestAllowedMode;

		$source = wfMessage( 'cirrussearch-didyoumean-settings' )->inContentLanguage();
		if ( !$source || $source->isDisabled() ) {
			return;
		}
		$lines = Util::parseSettingsInMessage( $source->plain() );

		// Keep original alpha or discount settings
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace']['alpha'] ) ) {
			$laplaceAlpha = $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace']['alpha'];
		}
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff']['discount'] ) ) {
			$stupidBackoffDiscount = $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff']['discount'];
		}

		foreach ( $lines as $line ) {
			$linePieces = explode( ':', $line, 2 );
			if ( count( $linePieces ) ) {
				// Skip improperly formatted lines without a key:value
				continue;
			}
			$k = $linePieces[0];
			$v = $linePieces[1];

			switch( $k ) {
			case 'max_errors' :
				if ( is_numeric( $v ) && $v >= 1 && $v <= $wgCirrusSearchPhraseSuggestMaxErrorsHardLimit ) {
					$wgCirrusSearchPhraseSuggestSettings['max_errors'] = floatval($v);
					// Support deprecated settings
					if ( isset ( $wgCirrusSearchPhraseSuggestMaxErrors ) ) {
						$wgCirrusSearchPhraseSuggestMaxErrors = floatval( $v );
					}
				}
				break;
			case 'confidence' :
				if ( is_numeric( $v ) && $v >= 0 ) {
					$wgCirrusSearchPhraseSuggestSettings['confidence'] = floatval( $v );
					if ( isset ( $wgCirrusSearchPhraseSuggestConfidence ) ) {
						$wgCirrusSearchPhraseSuggestConfidence = floatval( $v );
					}
				}
				break;
			case 'max_term_freq' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= $wgCirrusSearchPhraseSuggestMaxTermFreqHardLimit ) {
					$wgCirrusSearchPhraseSuggestSettings['max_term_freq'] = floatval( $v );
				}
				break;
			case 'min_doc_freq' :
				if ( is_numeric( $v ) && $v >= 0 && $v < 1 ) {
					$wgCirrusSearchPhraseSuggestSettings['min_doc_freq'] = floatval( $v );
				}
				break;
			case 'prefix_length' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= $wgCirrusSearchPhraseSuggestPrefixLengthHardLimit ) {
					$wgCirrusSearchPhraseSuggestSettings['prefix_length'] = intval( $v );
				}
				break;
			case 'suggest_mode' :
				if ( in_array( $v, $wgCirrusSearchPhraseSuggestAllowedMode ) ) {
					$wgCirrusSearchPhraseSuggestSettings['mode'] = $v;
				}
				break;
			case 'collate' :
				if ( $v === 'true' ) {
					$wgCirrusSearchPhraseSuggestSettings['collate'] = true;
				} else if ( $v === 'false' ) {
					$wgCirrusSearchPhraseSuggestSettings['collate'] = false;
				}
				break;
			case 'smoothing' :
				if ( $v === 'laplace' ) {
					$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = array(
						'laplace' => array(
							'alpha' => 0.5
						)
					);
				} else if ( $v === 'stupid_backoff' ) {
					$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = array(
						'stupid_backoff' => array(
							'discount' => 0.4
						)
					);
				}
				break;
			case 'laplace_alpha' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= 1 ) {
					$laplaceAlpha = floatval($v);
				}
				break;
			case 'stupid_backoff_discount' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= 1 ) {
					$stupidBackoffDiscount = floatval($v);
				}
				break;
			}
		}

		// Apply smoothing model options, if none provided we'll use elasticsearch defaults
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace'] ) &&
			isset ( $laplaceAlpha ) ) {
			$wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace'] = array(
				'alpha' => $laplaceAlpha
			);
		}
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff'] ) &&
			isset ( $stupidBackoffDiscount ) ) {
			$wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff'] = array(
				'discount' => $stupidBackoffDiscount
			);
		}
	}
}
