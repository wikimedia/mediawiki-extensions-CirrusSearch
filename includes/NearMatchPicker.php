<?php

namespace CirrusSearch;
use \ProfileSection;

/**
 * Picks the best "near match" title.
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
class NearMatchPicker {
	/**
	 * @var Language language to use during normalization process
	 */
	private $language;
	/**
	 * @var string the search term
	 */
	private $term;
	/**
	 * @var array(Title) potential near matches
	 */
	private $titles;

	/**
	 * Constructor
	 *
	 * @param Language $language to use during normalization process
	 * @param string $term the search term
	 * @param array(Title) $titles potential near matches
	 */
	public function __construct( $language, $term, $titles ) {
		$this->language = $language;
		$this->term = $term;
		$this->titles = $titles;
	}

	/**
	 * Pick the best near match if possible.
	 *
	 * @return Title|null title if there is a near match and null otherwise
	 */
	public function pickBest() {
		$profiler = new ProfileSection( __METHOD__ );

		if ( !$this->titles ) {
			return null;
		}
		if ( !$this->term ) {
			return null;
		}
		if ( count( $this->titles ) === 1 ) {
			return $this->titles[ 0 ];
		}

		$transformers = array(
			function( $term ) { return $term; },
			array( $this->language, 'lc' ),
			array( $this->language, 'ucwords' ),
		);

		foreach ( $transformers as $transformer ) {
			$transformedTerm = call_user_func( $transformer, $this->term );
			$found = null;
			foreach ( $this->titles as $title ) {
				$transformedTitle = call_user_func( $transformer, $title->getText() );
				// wfDebugLog( 'CirrusSearch', "Near match candidates: $transformedTerm  $transformedTitle");
				if ( $transformedTerm === $transformedTitle ) {
					if ( !$found ) {
						$found = $title;
					} else {
						// Found more than one result so we try another transformer
						$found = null;
						break;
					}
				}
			}
			if ( $found ) {
				return $found;
			}
		}

		// Didn't find anything
		return null;
	}
}