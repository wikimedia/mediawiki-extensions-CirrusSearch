<?php

namespace CirrusSearch\BuildDocument;
use SuggestScoringMethod;

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

class SuggestBuilder {
	const MAX_INPUT_LENGTH = 50;

	/**
	 * $scoringMethod the scoring function
	 */
	private $scoringMethod;

	/**
	 * @param SuggestScoringMethod
	 */
	public function __construct( $scoringMethod ) {
		$this->scoringMethod = $scoringMethod;
	}

	/**
	 * @param int $id the page id
	 * @param $inputDoc the page data
	 * @return array a set of suggest documents
	 */
	public function build( $id, $inputDoc ) {
		$score = $this->scoringMethod->score( $inputDoc );
		$inputs = $this->buildInputs( $inputDoc );
		$doc = array(
			'suggest' => array (
				'input' => $inputs,
				'output' => $id,
				'weight' => $score
			),
			'suggest-stop' => array (
				'input' => $inputs,
				'output' => $id,
				'weight' => $score
			)
		);

		// We support only earth and we take the first coordinate only...
		if ( isset ( $inputDoc['coordinates'][0]['globe'] ) && $inputDoc['coordinates'][0]['globe'] === 'earth' ) {
			$location = array( 'location' => $inputDoc['coordinates'][0]['coord'] );

			$doc['suggest-geo'] = array(
				'input' => $inputs,
				'output' => $id,
				'weight' => $score,
				'context' => $location
			);
			$doc['suggest-stop-geo'] = array(
				'input' => $inputs,
				'output' => $id,
				'weight' => $score,
				'context' => $location
			);
		}

		return array( $doc );
	}

	public function buildInputs( $input ) {
		$inputs = array( $this->prepareInput( $input['title'] ) );
		foreach ( $input['redirect'] as $redir ) {
			$inputs[] = $this->prepareInput( $redir['title'] );
		}
		return $inputs;
	}

	public function prepareInput( $input ) {
		if ( mb_strlen( $input ) > self::MAX_INPUT_LENGTH ) {
			$input = mb_substr( $input, 0, self::MAX_INPUT_LENGTH );
		}
		return $input;
	}
}
