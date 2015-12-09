<?php

namespace CirrusSearch\BuildDocument;

/**
 * Build a doc ready for the titlesuggest index.
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
 * Builder used to create suggester docs
 * NOTE: Experimental
 */
class SuggestBuilder {
	/**
	 * We limit the input to 50 chars
	 */
	const MAX_INPUT_LENGTH = 50;

	/**
	 * The acceptable edit distance to group similar strings
	 */
	const GROUP_ACCEPTABLE_DISTANCE = 2;

	/**
	 * Discount suggestions based on redirects
	 */
	const REDIRECT_DISCOUNT = 0.1;

	/**
	 * Number of common prefix chars a redirect must share with the title to be
	 * promoted as a title suggestion.
	 * This is useful not to promote Eraq as a title suggestion for Iraq
	 */
	const REDIRECT_COMMON_PREFIX_LEN = 1;

	/**
	 * @var SuggestScoringMethod the scoring function
	 */
	private $scoringMethod;

	/**
	 * @var boolean builds geo contextualized suggestions
	 */
	private $withGeo;

	/**
	 * @param SuggestScoringMethod $scoringMethod the scoring function to use
	 */
	public function __construct( SuggestScoringMethod $scoringMethod, $withGeo = true ) {
		$this->scoringMethod = $scoringMethod;
		$this->withGeo = $withGeo;
	}

	/**
	 * @param int $id the page id
	 * @param array $inputDoc the page data
	 * @return array a set of suggest documents
	 */
	public function build( $id, $inputDoc ) {
		if( !isset( $inputDoc['title'] ) ) {
			// Bad doc, nothing to do here.
			return array();
		}
		$score = $this->scoringMethod->score( $inputDoc );

		// We support only earth and the primary/first coordinates...
		$location = $this->findPrimaryCoordinates( $inputDoc );

		$suggestions = $this->extractTitleAndSimilarRedirects( $inputDoc );
		$docs[] = $this->buildTitleSuggestion( $id, $suggestions['group'], $location, $score );
		if ( !empty( $suggestions['candidates'] ) ) {
			$docs[] = $this->buildRedirectsSuggestion( $id, $suggestions['candidates'],
				$location, $score );
		}
		return $docs;
	}

	/**
	 * Inspects the 'coordinates' index and return the first coordinates flagged as 'primary'
	 * or the first coordinates if no primaries are found.
	 * @param array $inputDoc the input doc
	 * @return array with 'lat' and 'lon' or null
	 */
	public function findPrimaryCoordinates( $inputDoc ) {
		if ( !isset( $inputDoc['coordinates'] ) || !is_array( $inputDoc['coordinates'] ) ) {
			return null;
		}

		$first = null;
		foreach( $inputDoc['coordinates'] as $coord ) {
			if ( isset( $coord['globe'] ) && $coord['globe'] == 'earth' && isset( $coord['coord'] ) ) {
				if ( $first === null ) {
					$first = $coord['coord'];
				}
				if ( isset( $coord['primary'] ) && $coord['primary'] ) {
					return $coord['coord'];
				}
			}
		}
		return $first;
	}

	/**
	 * Builds the 'title' suggestion.
	 * The output is encoded as pageId:t:Title.
	 * NOTE: the client will be able to display Title encoded in the output when searching.
	 *
	 * @param int $id the page id
	 * @param array $title the title in 'text' and an array of similar redirects in 'variants'
	 * @param array $location the geo coordinates or null if unavailable
	 * @param int $score the weight of the suggestion
	 * @return array the suggestion document
	 */
	private function buildTitleSuggestion( $id, $title, $location, $score ) {
		$inputs = array( $this->prepareInput( $title['text'] ) );
		foreach ( $title['variants'] as $variant ) {
			$inputs[] = $this->prepareInput( $variant );
		}
		$output = $id . ":t:" . $title['text'];
		return $this->buildSuggestion( $output, $inputs, $location, $score );
	}

	/**
	 * Builds the 'redirects' suggestion.
	 * The output is encoded as pageId:r
	 * The score will be discounted by the REDIRECT_DISCOUNT factor.
	 * NOTE: the client will have to fetch the doc redirects when searching
	 * and choose the best one to display. This is because we are unable
	 * to make this decision at index time.
	 *
	 * @param int $id the page id
	 * @param string[] $redirects
	 * @param array $location the geo coordinates or null if unavailable
	 * @param int $score the weight of the suggestion
	 * @return array the suggestion document
	 */
	private function buildRedirectsSuggestion( $id, $redirects, $location, $score ) {
		$inputs = array();
		foreach ( $redirects as $redirect ) {
			$inputs[] = $this->prepareInput( $redirect );
		}
		$output = $id . ":r";
		$score = (int) ( $score * self::REDIRECT_DISCOUNT );
		return $this->buildSuggestion( $output, $inputs, $location, $score );
	}

	/**
	 * Builds a suggestion document.
	 *
	 * @param string $output the suggestion output
	 * @param string $inputs the suggestion inputs
	 * @param array $location the geo coordinates or null if unavailable
	 * @param int $score the weight of the suggestion
	 * @return array a doc ready to be indexed in the completion suggester
	 */
	private function buildSuggestion( $output, $inputs, $location, $score ) {
		$doc = array(
			'suggest' => array (
				'input' => $inputs,
				'output' => $output,
				'weight' => $score
			),
			'suggest-stop' => array (
				'input' => $inputs,
				'output' => $output,
				'weight' => $score
			)
		);

		if ( $this->withGeo && $location !== null ) {
			$doc['suggest-geo'] = array(
				'input' => $inputs,
				'output' => $output,
				'weight' => $score,
				'context' => array( 'location' => $location )
			);
			$doc['suggest-stop-geo'] = array(
				'input' => $inputs,
				'output' => $output,
				'weight' => $score,
				'context' => array( 'location' => $location )
			);
		}
		return $doc;
	}

	/**
	 * @param array $input Document to build inputs for
	 * @return array list of prepared suggestions that should
	 *  resolve to the document.
	 */
	public function buildInputs( $input ) {
		$inputs = array( $this->prepareInput( $input['text'] ) );
		foreach ( $input['variants'] as $variant ) {
			$inputs[] = $this->prepareInput( $variant );
		}
		return $inputs;
	}

	/**
	 * @param string $input A page title
	 * @return string A page title short enough to not cause indexing
	 *  issues.
	 */
	public function prepareInput( $input ) {
		if ( mb_strlen( $input ) > self::MAX_INPUT_LENGTH ) {
			$input = mb_substr( $input, 0, self::MAX_INPUT_LENGTH );
		}
		return $input;
	}

	/**
	 * Extracts title with redirects that are very close.
	 * It will allow to make one suggestion with title as the
	 * output and title + similar redirects as the inputs.
	 * It can be useful to avoid displaying redirects created to
	 * to handle typos.
	 *
	 * e.g. :
	 *   title: Giraffe
	 *   redirects: Girafe, Girraffe, Mating Giraffes
	 * will output
	 *   - 'group' : { 'text': 'Giraffe', 'variants': ['Girafe', 'Girraffe'] }
	 *   - 'candidates' : ['Mating Giraffes']
	 *
	 * It would be nice to do this for redirects but we have no way to decide
	 * which redirect is a typo and this technique would simply take the first
	 * redirect in the list.
	 *
	 * @return array mixed 'group' key contains the group with the
	 *         lead and its variants and 'candidates' contains the remaining
	 *         candidates that were not close enough to $groupHead.
	 */
	public function extractTitleAndSimilarRedirects( $doc ) {
		$redirects = array();
		if ( isset( $doc['redirect'] ) ) {
			foreach( $doc['redirect'] as $redir ) {
				$redirects[] = $redir['title'];
			}
		}
		return $this->extractSimilars( $doc['title'], $redirects, true );
	}

	/**
	 * Extracts from $candidates the values that are "similar" to $groupHead
	 *
	 * @param string $groupHead string the group "head"
	 * @param array $candidates array of string the candidates
	 * @param boolean $checkVariants if the candidate does not match the groupHead try to match a variant
	 * @return array 'group' key contains the group with the
	 *         head and its variants and 'candidates' contains the remaining
	 *         candidates that were not close enough to $groupHead.
	 */
	private function extractSimilars( $groupHead, $candidates, $checkVariants = false ) {
		$group = array(
			'text' => $groupHead,
			'variants' => array()
		);
		$newCandidates = array();
		foreach( $candidates as $c ) {
			$distance = $this->distance( $groupHead, $c );
			if( $distance > self::GROUP_ACCEPTABLE_DISTANCE && $checkVariants ) {
				// Run a second pass over the variants
				foreach ( $group['variants'] as $v ) {
					$distance = $this->distance( $v, $c );
					if ( $distance <= self::GROUP_ACCEPTABLE_DISTANCE ) {
						break;
					}
				}
			}
			if ( $distance <= self::GROUP_ACCEPTABLE_DISTANCE ) {
				$group['variants'][] = $c;
			} else {
				$newCandidates[] = $c;
			}
		}

		return array(
			'group' => $group,
			'candidates' => $newCandidates
		);
	}

	/**
	 * Computes the edit distance between $a and $b.
	 * @param string $a
	 * @param string $b
	 * @return integer the edit distance between a and b
	 */
	private function distance( $a, $b ) {
		$a = $this->prepareInput( $a );
		$b = $this->prepareInput( $b );
		$a = mb_strtolower( $a );
		$b = mb_strtolower( $b );

		$aLength = mb_strlen( $a );
		$bLength = mb_strlen( $b );

		$commonPrefixLen = self::REDIRECT_COMMON_PREFIX_LEN;

		if ( $aLength < $commonPrefixLen ) {
			$commonPrefixLen = $aLength;
		}
		if( $bLength < $commonPrefixLen ) {
			$commonPrefixLen = $bLength;
		}

		// check the common prefix
		if ( mb_substr( $a, 0, $commonPrefixLen ) != mb_substr( $b, 0, $commonPrefixLen ) ) {
			return PHP_INT_MAX;
		}

		// TODO: switch to a ratio instead of raw distance would help to group
		// longer strings
		return levenshtein( $a, $b );
	}
}
