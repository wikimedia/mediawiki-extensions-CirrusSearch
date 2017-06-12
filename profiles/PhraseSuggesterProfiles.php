<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;

/**
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

class PhraseSuggesterProfiles {
	/**
	 * Override Phrase suggester options ("Did you mean?" suggestions)
	 *
	 * @param \WebRequest $request
	 */
	public static function overrideOptions( $request ) {
		global $wgCirrusSearchPhraseSuggestMaxErrors,
			$wgCirrusSearchPhraseSuggestConfidence,
			$wgCirrusSearchPhraseSuggestSettings,
			$wgCirrusSearchPhraseSuggestMaxTermFreqHardLimit,
			$wgCirrusSearchPhraseSuggestMaxErrorsHardLimit,
			$wgCirrusSearchPhraseSuggestPrefixLengthHardLimit,
			$wgCirrusSearchPhraseSuggestAllowedMode,
			$wgCirrusSearchPhraseSuggestAllowedSmoothingModel,
			$wgCirrusSearchPhraseSuggestReverseField;

		Util::overrideYesNo( $wgCirrusSearchPhraseSuggestReverseField['use'], $request,
			'cirrusSuggUseReverse' );
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
				$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = [
					'laplace' => [
						'alpha' => 0.5
					]
				];
				break;
			case 'stupid_backoff' :
				$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = [
					'stupid_backoff' => [
						'discount' => 0.4
					]
				];
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

		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		$lines = $cache->getWithSetCallback(
			$cache->makeKey( 'cirrussearch-didyoumean-settings' ),
			600,
			function () {
				$source = wfMessage( 'cirrussearch-didyoumean-settings' )->inContentLanguage();
				if ( !$source || $source->isDisabled() ) {
					return [];
				}
				return Util::parseSettingsInMessage( $source->plain() );
			}
		);

		// Keep original alpha or discount settings
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace']['alpha'] ) ) {
			$laplaceAlpha = $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace']['alpha'];
		}
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff']['discount'] ) ) {
			$stupidBackoffDiscount = $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff']['discount'];
		}

		$laplaceAlpha = null;
		$stupidBackoffDiscount = null;
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
				} elseif ( $v === 'false' ) {
					$wgCirrusSearchPhraseSuggestSettings['collate'] = false;
				}
				break;
			case 'smoothing' :
				if ( $v === 'laplace' ) {
					$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = [
						'laplace' => [
							'alpha' => 0.5
						]
					];
				} elseif ( $v === 'stupid_backoff' ) {
					$wgCirrusSearchPhraseSuggestSettings['smoothing_model'] = [
						'stupid_backoff' => [
							'discount' => 0.4
						]
					];
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
			$wgCirrusSearchPhraseSuggestSettings['smoothing_model']['laplace'] = [
				'alpha' => $laplaceAlpha
			];
		}
		if ( isset ( $wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff'] ) &&
			isset ( $stupidBackoffDiscount ) ) {
			$wgCirrusSearchPhraseSuggestSettings['smoothing_model']['stupid_backoff'] = [
				'discount' => $stupidBackoffDiscount
			];
		}
	}
}
