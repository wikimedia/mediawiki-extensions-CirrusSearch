<?php
namespace CirrusSearch\Search;

/**
 * Index field representing keyword.
 * Keywords use special analyzer.
 * @package CirrusSearch
 */
class KeywordIndexField extends CirrusIndexField {
	protected $typeName = 'string';

	/**
	 * Maximum number of characters allowed in keyword terms.
	 */
	const KEYWORD_IGNORE_ABOVE = 5000;

	public function getMapping( \SearchEngine $engine ) {
		$config = parent::getMapping( $engine );
		$config['analyzer'] =
			$this->checkFlag( self::FLAG_CASEFOLD ) ? 'lowercase_keyword' : 'keyword';
		$config += [
			'norms' => [ 'enabled' => false ],
			// Omit the length norm because there is only even one token
			'index_options' => 'docs',
			// Omit the frequency and position information because neither are useful
			'ignore_above' => self::KEYWORD_IGNORE_ABOVE,
		];
		return $config;
	}
}