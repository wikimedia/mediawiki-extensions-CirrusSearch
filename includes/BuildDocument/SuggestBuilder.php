<?php

namespace CirrusSearch\BuildDocument;

use Title;
use LinkBatch;

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
	 * Discount suggestions based on cross namespace redirects
	 */
	const CROSSNS_DISCOUNT = 0.005;

	/**
	 * Redirect suggestion type
	 */
	const REDIRECT_SUGGESTION = 'r';

	/**
	 * Title suggestion type
	 */
	const TITLE_SUGGESTION = 't';

	/**
	 * Number of common prefix chars a redirect must share with the title to be
	 * promoted as a title suggestion.
	 * This is useful not to promote Eraq as a title suggestion for Iraq
	 * Less than 3 can lead to weird results like oba => Osama Bin Laden
	 * @todo: to avoid displaying typos (if the typo is in the 3 chars)
	 * we could re-work Utils::chooseBestRedirect and display the title
	 * if the chosen redirect is close enough to the title.
	 */
	const REDIRECT_COMMON_PREFIX_LEN = 3;

	/**
	 * @var SuggestScoringMethod the scoring function
	 */
	private $scoringMethod;

	/**
	 * @var integer batch id
	 */
	private $batchId;

	/**
	 * @var boolean builds geo contextualized suggestions
	 */
	private $withGeo;

	/**
	 * @var boolean set to true to add an extra title suggestion with defaultsort
	 */
	private $withDefaultSort;

	/**
	 * NOTE: Currently a fixed value because the completion suggester does not support
	 * multi namespace suggestion.
	 *
	 * @var int $targetNamespace
	 */
	private $targetNamespace = NS_MAIN;

	/**
	 * @param SuggestScoringMethod $scoringMethod the scoring function to use
	 * @param bool $withGeo
	 */
	public function __construct( SuggestScoringMethod $scoringMethod, $withGeo = true, $withDefaultSort = false ) {
		$this->scoringMethod = $scoringMethod;
		$this->withGeo = $withGeo;
		$this->withDefaultSort = $withDefaultSort;
		$this->batchId = time();
	}

	/**
	 * @param array[] $inputDocs a batch of docs to build
	 * @return \Elastica\Document[] a set of suggest documents
	 */
	public function build( $inputDocs ) {
		// Cross namespace titles
		$crossNsTitles = [];
		$docs = [];
		foreach ( $inputDocs as $sourceDoc ) {
			$inputDoc = $sourceDoc['source'];
			$docId = $sourceDoc['id'];
			if ( !isset( $inputDoc['namespace'] ) ) {
				// Bad doc, nothing to do here.
				continue;
			}
			if( $inputDoc['namespace'] == NS_MAIN ) {
				if ( !isset( $inputDoc['title'] ) ) {
					// Bad doc, nothing to do here.
					continue;
				}
				$docs = array_merge( $docs, $this->buildNormalSuggestions( $docId, $inputDoc ) );
			} else {
				if ( !isset( $inputDoc['redirect'] ) ) {
					// Bad doc, nothing to do here.
					continue;
				}

				foreach ( $inputDoc['redirect'] as $redir ) {
					if ( !isset( $redir['namespace'] ) || !isset( $redir['title'] ) ) {
						continue;
					}
					if ( $redir['namespace'] != $this->targetNamespace ) {
						continue;
					}
					$score = $this->scoringMethod->score( $inputDoc );
					// Discount the score of these suggestions.
					$score = (int) ($score * self::CROSSNS_DISCOUNT);
					// We support only earth and the primary/first coordinates...
					$location = $this->findPrimaryCoordinates( $inputDoc );

					$title = Title::makeTitle( $redir['namespace'], $redir['title'] );
					$crossNsTitles[$redir['title']] = [
						'title' => $title,
						'score' => $score,
						'location' => $location
					];
				}
			}
		}

		// Build cross ns suggestions
		if ( !empty ( $crossNsTitles ) ) {
			$titles = [];
			foreach( $crossNsTitles as $text => $data ) {
				$titles[] = $data['title'];
			}
			$lb = new LinkBatch( $titles );
			$lb->setCaller( __METHOD__ );
			$lb->execute();
			// This is far from perfect:
			// - we won't try to group similar redirects since we don't know which one
			//   is the official one
			// - we will certainly suggest multiple times the same pages
			// - we must not run a second pass at query time: no redirect suggestion
			foreach ( $crossNsTitles as $text => $data ) {
				$suggestion = [
					'text' => $text,
					'variants' => []
				];
				$docs[] = $this->buildTitleSuggestion( $data['title']->getArticleID(), $suggestion, $data['location'], $data['score'] );
			}
		}
		return $docs;
	}

	/**
	 * Build classic suggestion
	 *
	 * @param string $docId
	 * @param array $inputDoc
	 * @return \Elastica\Document[] a set of suggest documents
	 */
	private function buildNormalSuggestions( $docId, array $inputDoc ) {
		if ( !isset( $inputDoc['title'] ) ) {
			// Bad doc, nothing to do here.
			return [];
		}

		$score = $this->scoringMethod->score( $inputDoc );

		// We support only earth and the primary/first coordinates...
		$location = $this->findPrimaryCoordinates( $inputDoc );

		$suggestions = $this->extractTitleAndSimilarRedirects( $inputDoc );
		if ( $this->withDefaultSort && !empty( $inputDoc['defaultsort'] ) ) {
			$suggestions['group']['variants'][] = $inputDoc['defaultsort'];
		}

		$docs[] = $this->buildTitleSuggestion( $docId, $suggestions['group'], $location, $score );
		if ( !empty( $suggestions['candidates'] ) ) {
			$docs[] = $this->buildRedirectsSuggestion( $docId, $suggestions['candidates'],
				$location, $score );
		}
		return $docs;
	}

	/**
	 * The fields needed to build and score documents.
	 *
	 * @return string[] the list of fields
	 */
	public function getRequiredFields() {
		$fields = $this->scoringMethod->getRequiredFields();
		$fields = array_merge( $fields, [ 'title', 'redirect', 'namespace' ] );
		if ( $this->withGeo ) {
			$fields[] = 'coordinates';
		}
		if ( $this->withDefaultSort ) {
			$fields[] = 'defaultsort';
		}
		return $fields;
	}

	/**
	 * Inspects the 'coordinates' index and return the first coordinates flagged as 'primary'
	 * or the first coordinates if no primaries are found.
	 *
	 * @param array $inputDoc the input doc
	 * @return array|null with 'lat' and 'lon' or null
	 */
	public function findPrimaryCoordinates( array $inputDoc ) {
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
	 * @param string $docId the page id
	 * @param array $title the title in 'text' and an array of similar redirects in 'variants'
	 * @param array|null $location the geo coordinates or null if unavailable
	 * @param int $score the weight of the suggestion
	 * @return \Elastica\Document the suggestion document
	 */
	private function buildTitleSuggestion( $docId, array $title, array $location = null, $score ) {
		$inputs = [ $this->prepareInput( $title['text'] ) ];
		foreach ( $title['variants'] as $variant ) {
			$inputs[] = $this->prepareInput( $variant );
		}
		$output = self::encodeTitleOutput( $docId, $title['text'] );
		return $this->buildSuggestion(
			self::TITLE_SUGGESTION . $docId,
			$output,
			$inputs,
			$location,
			$score
		);
	}

	/**
	 * Builds the 'redirects' suggestion.
	 * The output is encoded as pageId:r
	 * The score will be discounted by the REDIRECT_DISCOUNT factor.
	 * NOTE: the client will have to fetch the doc redirects when searching
	 * and choose the best one to display. This is because we are unable
	 * to make this decision at index time.
	 *
	 * @param string $docId the elasticsearch document id
	 * @param string[] $redirects
	 * @param array|null $location the geo coordinates or null if unavailable
	 * @param int $score the weight of the suggestion
	 * @return \Elastica\Document the suggestion document
	 */
	private function buildRedirectsSuggestion( $docId, array $redirects, array $location = null, $score ) {
		$inputs = [];
		foreach ( $redirects as $redirect ) {
			$inputs[] = $this->prepareInput( $redirect );
		}
		$output = $docId . ":" . self::REDIRECT_SUGGESTION;
		$score = (int) ( $score * self::REDIRECT_DISCOUNT );
		return $this->buildSuggestion( self::REDIRECT_SUGGESTION . $docId, $output, $inputs, $location, $score );
	}

	/**
	 * Builds a suggestion document.
	 *
	 * @param string $docId The document id
	 * @param string $output the suggestion output
	 * @param string[] $inputs the suggestion inputs
	 * @param array|null $location the geo coordinates or null if unavailable
	 * @param int $score the weight of the suggestion
	 * @return \Elastica\Document a doc ready to be indexed in the completion suggester
	 */
	private function buildSuggestion( $docId, $output, array $inputs, array $location = null, $score ) {
		$doc = [
			'batch_id' => $this->batchId,
			'suggest' => [
				'input' => $inputs,
				'output' => $output,
				'weight' => $score
			],
			'suggest-stop' => [
				'input' => $inputs,
				'output' => $output,
				'weight' => $score
			]
		];

		if ( $this->withGeo && $location !== null ) {
			$doc['suggest-geo'] = [
				'input' => $inputs,
				'output' => $output,
				'weight' => $score,
				'context' => [ 'location' => $location ]
			];
			$doc['suggest-stop-geo'] = [
				'input' => $inputs,
				'output' => $output,
				'weight' => $score,
				'context' => [ 'location' => $location ]
			];
		}
		return new \Elastica\Document( $docId, $doc );
	}

	/**
	 * @param array $input Document to build inputs for
	 * @return array list of prepared suggestions that should
	 *  resolve to the document.
	 */
	public function buildInputs( array $input ) {
		$inputs = [ $this->prepareInput( $input['text'] ) ];
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
	 * @param array $doc
	 * @return array mixed 'group' key contains the group with the
	 *         lead and its variants and 'candidates' contains the remaining
	 *         candidates that were not close enough to $groupHead.
	 */
	public function extractTitleAndSimilarRedirects( array $doc ) {
		$redirects = [];
		if ( isset( $doc['redirect'] ) ) {
			foreach( $doc['redirect'] as $redir ) {
				// Avoid suggesting/displaying non existent titles
				// in the target namespace
				if( $redir['namespace'] == $this->targetNamespace ) {
					$redirects[] = $redir['title'];
				}
			}
		}
		return $this->extractSimilars( $doc['title'], $redirects, true );
	}

	/**
	 * Extracts from $candidates the values that are "similar" to $groupHead
	 *
	 * @param string $groupHead string the group "head"
	 * @param string[] $candidates array of string the candidates
	 * @param boolean $checkVariants if the candidate does not match the groupHead try to match a variant
	 * @return array 'group' key contains the group with the
	 *         head and its variants and 'candidates' contains the remaining
	 *         candidates that were not close enough to $groupHead.
	 */
	private function extractSimilars( $groupHead, array $candidates, $checkVariants = false ) {
		$group = [
			'text' => $groupHead,
			'variants' => []
		];
		$newCandidates = [];
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

		return [
			'group' => $group,
			'candidates' => $newCandidates
		];
	}

	/**
	 * Computes the edit distance between $a and $b.
	 *
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

	/**
	 * Encode a title suggestion output
	 *
	 * @param string $docId elasticsearch document id
	 * @param string $title
	 * @return string the encoded output
	 */
	public static function encodeTitleOutput( $docId, $title ) {
		return $docId . ':'. self::TITLE_SUGGESTION . ':' . $title;
	}

	/**
	 * Encode a redirect suggestion output
	 *
	 * @param string $docId elasticsearch document id
	 * @return string the encoded output
	 */
	public static function encodeRedirectOutput( $docId ) {
		return $docId . ':' . self::REDIRECT_SUGGESTION;
	}

	/**
	 * Decode a suggestion output.
	 * The result is an array with the following keys:
	 * id: the pageId
	 * type: either REDIRECT_SUGGESTION or TITLE_SUGGESTION
	 * text (optional): if TITLE_SUGGESTION the Title text
	 *
	 * @param string $output text value returned by a suggest query
	 * @return string[]|null array of strings, or null if the output is not properly encoded
	 */
	public static function decodeOutput( $output ) {
		if ( $output == null ) {
			return null;
		}
		$parts = explode( ':', $output, 3 );
		if ( sizeof ( $parts ) < 2 ) {
			// Ignore broken output
			return null;
		}


		switch( $parts[1] ) {
		case self::REDIRECT_SUGGESTION:
			return [
				'docId' => $parts[0],
				'type' => self::REDIRECT_SUGGESTION,
			];
		case self::TITLE_SUGGESTION:
			if ( sizeof( $parts ) < 3 ) {
				return null;
			}
			return [
				'docId' => $parts[0],
				'type' => self::TITLE_SUGGESTION,
				'text' => $parts[2]
			];
		}
		return null;
	}

	/**
	 * @return int the batchId
	 */
	public function getBatchId() {
		return $this->batchId;
	}
}
