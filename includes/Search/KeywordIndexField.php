<?php

namespace CirrusSearch\Search;

use CirrusSearch\SearchConfig;
use SearchIndexField;

/**
 * Index field representing keyword.
 * Keywords use special analyzer.
 * @package CirrusSearch
 */
class KeywordIndexField extends CirrusIndexField {
	/**
	 * @var string
	 */
	protected $typeName = 'keyword';
	/**
	 * @var bool
	 */
	private $caseSensitiveSubfield;
	/** @var bool true to skip doc values */
	private bool $withDocValues;

	/**
	 * @param string $name
	 * @param string $type
	 * @param SearchConfig $config
	 * @param bool $caseSensitiveSubfield
	 * @param bool $withDocValues set to true to enable indexing/storing doc values
	 */
	public function __construct( $name, $type, SearchConfig $config, bool $caseSensitiveSubfield = false, bool $withDocValues = false ) {
		parent::__construct( $name, $type, $config );
		if ( $caseSensitiveSubfield ) {
			$this->setFlag( SearchIndexField::FLAG_CASEFOLD );
		}
		$this->caseSensitiveSubfield = $caseSensitiveSubfield;
		$this->withDocValues = $withDocValues;
	}

	public function withDocValues(): self {
		$this->withDocValues = true;
		return $this;
	}

	/** @inheritDoc */
	public function getMapping( \SearchEngine $engine ) {
		$config = parent::getMapping( $engine );
		$config['doc_values'] = $this->withDocValues;
		$config['normalizer'] =
			$this->checkFlag( self::FLAG_CASEFOLD ) ? 'lowercase_keyword' : 'keyword';
		if ( $this->caseSensitiveSubfield ) {
			$config['fields']['keyword'] = [
				'type' => 'keyword',
				'normalizer' => 'keyword',
				'doc_values' => $this->withDocValues
			];
		}
		return $config;
	}
}
