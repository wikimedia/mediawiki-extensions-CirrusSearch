<?php

namespace CirrusSearch\Search;

/**
 * Index field representing keyword.
 * Keywords use special analyzer.
 * @package CirrusSearch
 */
class KeywordIndexField extends CirrusIndexField {
	protected $typeName = 'text';

	/**
	 * Maximum number of characters allowed in keyword terms.
	 */
	const KEYWORD_IGNORE_ABOVE = 5000;

	public function getMapping( \SearchEngine $engine ) {
		// TODO: Should we vary between keyword and text type? For now using text
		// because keyword doesn't support analyzers for lowercasing.
		$config = parent::getMapping( $engine );
		// TODO with keyword type in ES 5.2 this will become 'normalizer'
		$config['analyzer'] =
			$this->checkFlag( self::FLAG_CASEFOLD ) ? 'lowercase_keyword' : 'keyword';
		$config += [
			'norms' => false,
			// Omit the length norm because there is only even one token
			'index_options' => 'docs',
			// TODO: Re-enable after upgrade to es 5.2 and changing type to keyword
			// Omit the frequency and position information because neither are useful
			// 'ignore_above' => self::KEYWORD_IGNORE_ABOVE,
		];
		return $config;
	}
}
