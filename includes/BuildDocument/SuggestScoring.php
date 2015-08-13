<?php

namespace CirrusSearch\BuildDocument;

/**
 * Scoring methods used by the completion suggester
 *
 * Set $wgSearchType to 'CirrusSearch'
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


class SuggestScoringMethodFactory {
	/**
	 * @param $scoringMethods string the name of the scoring method
	 * @return SuggestScoringMethod
	 */
	public static function getScoringMethod( $scoringMethod ) {
		switch( $scoringMethod ) {
		case 'incomingLinks':
			return new IncomingsLinksScoringMethod();
		}
		throw new \Exception( 'Unknown scoring method ' . $scoringMethod );
	}
}

interface SuggestScoringMethod {
	/**
	 * @param $doc array A document from the PAGE type
	 * @return int the weight of the document
	 */
	public function score( $doc );
}


/**
 * Very simple scoring method based on incoming links
 */
class IncomingsLinksScoringMethod implements SuggestScoringMethod {
	public function score( $doc ) {
		return $doc['incoming_links'];
	}
}
