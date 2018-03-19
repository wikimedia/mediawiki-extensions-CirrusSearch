<?php

namespace CirrusSearch\Search;

/**
 * Index field representing keyword.
 * Keywords use special analyzer.
 * @package CirrusSearch
 */
class KeywordIndexField extends CirrusIndexField {
	/**
	 * Using text type here since it's better for our purposes than native
	 * keyword type.
	 * @var string
	 */
	protected $typeName = 'text';

	/**
	 * Maximum number of characters allowed in keyword terms.
	 */
	const KEYWORD_IGNORE_ABOVE = 5000;

	public function getMapping( \SearchEngine $engine ) {
		$config = parent::getMapping( $engine );
		$config['analyzer'] =
			$this->checkFlag( self::FLAG_CASEFOLD ) ? 'lowercase_keyword' : 'keyword';
		$config += [
			'norms' => false,
			// Omit the length norm because there is only even one token
			'index_options' => 'docs',
		];
		return $config;
	}
}
