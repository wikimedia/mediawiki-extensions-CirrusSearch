<?php
namespace CirrusSearch\Search;

use CirrusSearch\Search\TextIndexField;

/**
 * Simple TextIndexField subclass useful to customize COPY_TO_SUGGEST
 * @package CirrusSearch
 */
class OpeningTextIndexField extends TextIndexField {
	/**
	 * Force COPY_TO_SUGGEST if CirrusSearchPhraseSuggestUseOpeningText
	 * is set.
	 * {@inheritDoc}
	 */
	protected function getTextOptions( $mappingFlags ) {
		$options = parent::getTextOptions( $mappingFlags );
		if ( $this->config->get( 'CirrusSearchPhraseSuggestUseOpeningText' ) ) {
			$options |= self::COPY_TO_SUGGEST;
		}
		return $options;
	}
}
