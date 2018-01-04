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
	const MAX_ERRORS_HARD_LIMIT = 2;
	const MAX_TERM_FREQ_HARD_LIMIT = 0.6;
	/**
	 * @var string[]
	 */
	private static $ALLOWED_MODE = [ 'missing', 'popular', 'always' ];

	const PREFIX_LENGTH_HARD_LIMIT = 2;

	/**
	 * Override Phrase suggester options ("Did you mean?" suggestions)
	 * @param array $settings
	 * @return array
	 */
	public static function overrideOptionsFromMessage( $settings ) {
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

			switch ( $k ) {
			case 'max_errors' :
				if ( is_numeric( $v ) && $v >= 1 && $v <= self::MAX_ERRORS_HARD_LIMIT ) {
					$settings['max_errors'] = floatval( $v );
				}
				break;
			case 'confidence' :
				if ( is_numeric( $v ) && $v >= 0 ) {
					$settings['confidence'] = floatval( $v );
				}
				break;
			case 'max_term_freq' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= self::MAX_TERM_FREQ_HARD_LIMIT ) {
					$settings['max_term_freq'] = floatval( $v );
				}
				break;
			case 'min_doc_freq' :
				if ( is_numeric( $v ) && $v >= 0 && $v < 1 ) {
					$settings['min_doc_freq'] = floatval( $v );
				}
				break;
			case 'prefix_length' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= self::PREFIX_LENGTH_HARD_LIMIT ) {
					$settings['prefix_length'] = intval( $v );
				}
				break;
			case 'suggest_mode' :
				if ( in_array( $v, self::$ALLOWED_MODE ) ) {
					$settings['mode'] = $v;
				}
				break;
			case 'collate' :
				if ( $v === 'true' ) {
					$settings['collate'] = true;
				} elseif ( $v === 'false' ) {
					$settings['collate'] = false;
				}
				break;
			case 'smoothing' :
				if ( $v === 'laplace' ) {
					$settings['smoothing_model'] = [
						'laplace' => [
							'alpha' => 0.5
						]
					];
				} elseif ( $v === 'stupid_backoff' ) {
					$settings['smoothing_model'] = [
						'stupid_backoff' => [
							'discount' => 0.4
						]
					];
				}
				break;
			case 'laplace_alpha' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= 1 ) {
					$laplaceAlpha = floatval( $v );
				}
				break;
			case 'stupid_backoff_discount' :
				if ( is_numeric( $v ) && $v >= 0 && $v <= 1 ) {
					$stupidBackoffDiscount = floatval( $v );
				}
				break;
			}
		}

		// Apply smoothing model options, if none provided we'll use elasticsearch defaults
		if ( isset( $settings['smoothing_model']['laplace'] ) &&
			isset( $laplaceAlpha ) ) {
			$settings['smoothing_model']['laplace'] = [
				'alpha' => $laplaceAlpha
			];
		}
		if ( isset( $settings['smoothing_model']['stupid_backoff'] ) &&
			isset( $stupidBackoffDiscount ) ) {
			$settings['smoothing_model']['stupid_backoff'] = [
				'discount' => $stupidBackoffDiscount
			];
		}
		return $settings;
	}
}
