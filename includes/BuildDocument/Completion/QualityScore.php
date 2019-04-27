<?php

namespace CirrusSearch\BuildDocument\Completion;

use CirrusSearch\Util;

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

	const EXTERNAL_LINKS_NORM = 20;
	const PAGE_SIZE_NORM = 50000;
	const HEADING_NORM = 20;
	const REDIRECT_NORM = 30;

	const INCOMING_LINKS_WEIGHT = 0.6;
	const EXTERNAL_LINKS_WEIGHT = 0.1;
	const PAGE_SIZE_WEIGHT = 0.1;
	const HEADING_WEIGHT = 0.2;
	const REDIRECT_WEIGHT = 0.1;

	// The final score will be in the range [0, SCORE_RANGE]
	const SCORE_RANGE = 10000000;

	/**
	 * Template boosts configured by the mediawiki admin.
	 *
	 * @var float[] array of key values, key is the template and value is a float
	 */
	private $boostTemplates;

	/**
	 * @var int the number of docs in the index
	 */
	protected $maxDocs;

	/**
	 * @var int normalisation factor for incoming links
	 */
	private $incomingLinksNorm;

	/**
	 * @param float[]|null $boostTemplates Array of key values, key is the template name, value the
	 *     boost factor. Defaults to Util::getDefaultBoostTemplates()
	 */
	public function __construct( $boostTemplates = null ) {
		$this->boostTemplates = $boostTemplates ?: Util::getDefaultBoostTemplates();
	}

	/**
	 * @inheritDoc
	 */
	public function score( array $doc ) {
		return intval( $this->intermediateScore( $doc ) * self::SCORE_RANGE );
	}

	protected function intermediateScore( array $doc ) {
		$incLinks = $this->scoreNormL2( $doc['incoming_links'] ?? 0,
			$this->incomingLinksNorm );
		$pageSize = $this->scoreNormL2( $doc['text_bytes'] ?? 0,
			self::PAGE_SIZE_NORM );
		$extLinks = $this->scoreNorm( isset( $doc['external_link'] )
			? count( $doc['external_link'] ) : 0, self::EXTERNAL_LINKS_NORM );
		$headings = $this->scoreNorm( isset( $doc['heading'] )
			? count( $doc['heading'] ) : 0, self::HEADING_NORM );
		$redirects = $this->scoreNorm( isset( $doc['redirect'] )
			? count( $doc['redirect'] ) : 0, self::REDIRECT_NORM );

		$score = $incLinks * self::INCOMING_LINKS_WEIGHT;

		$score += $extLinks * self::EXTERNAL_LINKS_WEIGHT;
		$score += $pageSize * self::PAGE_SIZE_WEIGHT;
		$score += $headings * self::HEADING_WEIGHT;
		$score += $redirects * self::REDIRECT_WEIGHT;

		// We have a standardized composite score between 0 and 1
		$score /= self::INCOMING_LINKS_WEIGHT + self::EXTERNAL_LINKS_WEIGHT +
				self::PAGE_SIZE_WEIGHT + self::HEADING_WEIGHT + self::REDIRECT_WEIGHT;

		return $this->boostTemplates( $doc, $score );
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
	public function boostTemplates( array $doc, $score ) {
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

		// Transform the boost to a value between -1 and 1
		$boost = $boost > 1 ? 1 - ( 1 / $boost ) : - ( 1 - $boost );
		// @todo: the 0.5 ratio is hardcoded we could maybe allow customization
		// here, this would be a way to increase the impact of template boost
		if ( $boost > 0 ) {
			return $score + ( ( ( 1 - $score ) / 2 ) * $boost );
		} else {
			return $score + ( ( $score / 2 ) * $boost );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getRequiredFields() {
		return [
			'incoming_links',
			'external_link',
			'text_bytes',
			'heading',
			'redirect',
			'template',
		];
	}

	/**
	 * @param int $maxDocs
	 */
	public function setMaxDocs( $maxDocs ) {
		$this->maxDocs = $maxDocs;
		// We normalize incoming links according to the size of the index
		$this->incomingLinksNorm = (int)( $maxDocs * self::INCOMING_LINKS_MAX_DOCS_FACTOR );
		if ( $this->incomingLinksNorm < 1 ) {
			// it's a very small wiki let's force the norm to 1
			$this->incomingLinksNorm = 1;
		}
	}
}
