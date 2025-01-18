<?php

namespace CirrusSearch\Search;

use SearchEngine;

/**
 * Index field representing datetime field.
 * @package CirrusSearch
 */
class DatetimeIndexField extends CirrusIndexField {

	/** @inheritDoc */
	protected $typeName = 'date';

	/** @inheritDoc */
	public function getMapping( SearchEngine $engine ) {
		$config = parent::getMapping( $engine );
		$config['format'] = 'dateOptionalTime';
		return $config;
	}
}
