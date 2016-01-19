<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Util;

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
	 * @param string $scoringMethod the name of the scoring method
	 * @param int $maxDocs
	 * @return SuggestScoringMethod
	 */
	public static function getScoringMethod( $scoringMethod, $maxDocs ) {
		switch( $scoringMethod ) {
		case 'incomingLinks':
			return new IncomingsLinksScoringMethod( $maxDocs );
		case 'quality':
			return new QualityScore( $maxDocs );
		}
		throw new \Exception( 'Unknown scoring method ' . $scoringMethod );
	}
}

interface SuggestScoringMethod {
	/**
	 * @param array $doc A document from the PAGE type
	 * @return int the weight of the document
	 */
	public function score( $doc );
}


/**
 * Very simple scoring method based on incoming links
 */
class IncomingsLinksScoringMethod implements SuggestScoringMethod {
	/**
	 * Constructor
	 * @param integer $maxDocs the number of docs in the index
	 */
	public function __construct( $maxDocs ) {
		// This scoring function is very simple and we
		// don't need to normalize
	}

	/**
	 * {@inheritDoc}
	 */
	public function score( $doc ) {
		return isset( $doc['incoming_links'] ) ? $doc['incoming_links'] : 0;
	}
}

/**
 * Score that tries to reflect the quality of a page.
 * NOTE: Experimental
 *
 * This score makes the assumption that bigger is better.
 *
 * Small cities/village which have a high number of incoming links because they
 * link to each others ( see https://en.wikipedia.org/wiki/Villefort,_Loz%C3%A8re )
 * will be be discounted correctly because others variables are very low.
 *
 * On the other hand some pages like List will get sometimes a very high but unjustified
 * score.
 *
 * The boost templates feature might help but it's a System message that is not necessarily
 * configured by wiki admins.
 */
class QualityScore implements SuggestScoringMethod {
	// TODO: move these constants into a cirrus profile
	const INCOMING_LINKS_MAX_DOCS_FACTOR = 0.1;

	const EXTERNAL_LINKS_NORM = 1000;
	const PAGE_SIZE_NORM = 300000;
	const HEADING_NORM = 50;
	const REDIRECT_NORM = 100;

	const INCOMING_LINKS_WEIGHT = 0.6;
	const EXTERNAL_LINKS_WEIGHT = 0.3;
	const PAGE_SIZE_WEIGHT = 0.1;
	const HEADING_WEIGHT = 0.2;
	const REDIRECT_WEIGHT = 0.1;

	// The final score will be in the range [0, SCORE_RANGE]
	const SCORE_RANGE = 10000000;

	/**
	 * Template boosts configured by the mediawiki admin.
	 * @var array of key values, key is the template and value is a float
	 */
	private $boostTemplates;

	/**
	 * @var integer the number of docs in the index
	 */
	private $maxDocs;

	/**
	 * @var integer normalisation factor for incoming links
	 */
	private $incomingLinksNorm;

	/**
	 * @param integer $maxDocs the number of docs in the index
	 * @param float[]|null $boostTemplates Array of key values, key is the template name, value the boost factor.
	 *        Defaults to Util::getDefaultBoostTemplates()
	 */
	public function __construct( $maxDocs, $boostTemplates = null ) {
		$this->maxDocs = $maxDocs;
		$this->boostTemplates = $boostTemplates ?: Util::getDefaultBoostTemplates();
		// We normalize incoming links according to the size of the index
		$this->incomingLinksNorm = (int) ($maxDocs * self::INCOMING_LINKS_MAX_DOCS_FACTOR);
		if ( $this->incomingLinksNorm < 1 ) {
			// it's a very small wiki let's force the norm to 1
			$this->incomingLinksNorm = 1;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function score( $doc ) {
		$incLinks = $this->scoreNormL2( isset( $doc['incoming_links'] ) ? $doc['incoming_links'] : 0, $this->incomingLinksNorm );
		$extLinks = $this->scoreNormL2( isset( $doc['external_link'] ) ? count( $doc['external_link'] ) : 0, self::EXTERNAL_LINKS_NORM );
		$pageSize = $this->scoreNormL2( isset( $doc['text_bytes'] ) ? $doc['text_bytes'] : 0, self::PAGE_SIZE_NORM );
		$headings = $this->scoreNorm( isset( $doc['heading'] ) ? count( $doc['heading'] ) : 0, self::HEADING_NORM );
		$redirects = $this->scoreNorm( isset( $doc['redirect'] ) ? count( $doc['redirect'] ) : 0, self::REDIRECT_NORM );

		$score = $incLinks * self::INCOMING_LINKS_WEIGHT;

		$score += $extLinks * self::EXTERNAL_LINKS_WEIGHT;
		$score += $pageSize * self::PAGE_SIZE_WEIGHT;
		$score += $headings * self::HEADING_WEIGHT;
		$score += $redirects * self::REDIRECT_WEIGHT;

		// We have a standardized composite score between 0 and 1
		$score /= self::INCOMING_LINKS_WEIGHT + self::EXTERNAL_LINKS_WEIGHT + self::PAGE_SIZE_WEIGHT + self::HEADING_WEIGHT + self::REDIRECT_WEIGHT;

		$score = $this->boostTemplates( $doc, $score );

		return intval( $score * self::SCORE_RANGE );
	}

	/**
	 * log2( ( value / norm ) + 1 ) => [0-1]
	 *
	 * @param float $value
	 * @param float $norm
	 * @return float between 0 and 1
	 */
	public function scoreNormL2( $value, $norm ) {
		return log( $value > $norm ? 2 : ( $value / $norm ) + 1, 2 );
	}

	/**
	 * value / norm => [0-1]
	 *
	 * @param float $value
	 * @param float $norm
	 * @return float between 0 and 1
	 */
	public function scoreNorm( $value, $norm ) {
		return $value > $norm ? 1 : $value / $norm;
	}

	/**
	 * Modify an existing score based on templates contained
	 * by the document.
	 *
	 * @param array $doc Document score is generated for
	 * @param float $score Current score between 0 and 1
	 * @return float Score after boosting templates
	 */
	public function boostTemplates( $doc, $score ) {
		if ( !isset( $doc['template'] ) ) {
			return $score;
		}

		if ( $this->boostTemplates ) {
			$boost = 1;
			// compute the global boost
			foreach ( $this->boostTemplates as $k => $v ) {
				if ( in_array( $k, $doc['template'] ) ) {
					$boost *= $v;
				}
			}
			if ( $boost != 1 ) {
				return $this->boost( $score, $boost );
			}
		}
		return $score;
	}

	/**
	 * Boost the score :
	 *   boost value lower than 1 will decrease the score
	 *   boost value set to 1 will keep the score unchanged
	 *   boost value greater than 1 will increase the score
	 *
	 * score = 0.5, boost = 0.5 result is 0.375
	 * score = 0.1, boost = 2 result is 0.325
	 *
	 * @param float $score
	 * @param float $boost
	 * @return float adjusted score
	 */
	public function boost( $score, $boost ) {
		if ( $boost == 1 ) {
			return $score;
		}

		$boost = $boost > 1 ? 1 - ( 1 / $boost ) : - ( 1 - $boost );
		if ( $boost > 0 ) {
			return $score + ( ( ( 1 - $score ) / 2 ) * $boost );
		} else {
			return $score + ( ( $score / 2 ) * $boost );
		}
	}
}
