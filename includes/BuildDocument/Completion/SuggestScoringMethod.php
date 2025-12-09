<?php

namespace CirrusSearch\BuildDocument\Completion;

/**
 * Scoring methods used by the completion suggester
 *
 * Set $wgSearchType to 'CirrusSearch'
 *
 * @license GPL-2.0-or-later
 */
interface SuggestScoringMethod {
	/**
	 * @param array $doc A document from the PAGE type
	 * @return int the weight of the document
	 */
	public function score( array $doc );

	/**
	 * The list of fields needed to compute the score.
	 *
	 * @return string[] the list of required fields
	 */
	public function getRequiredFields();

	/**
	 * This method will be called by the indexer script.
	 * some scoring method may want to normalize values based index size
	 *
	 * @param int $maxDocs the total number of docs in the index
	 */
	public function setMaxDocs( $maxDocs );

	/**
	 * Explain the score
	 * @param array $doc
	 * @return array
	 */
	public function explain( array $doc );
}
