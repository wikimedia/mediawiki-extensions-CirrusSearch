<?php

namespace CirrusSearch\Search;

use MediaWiki\Search\SearchEngine;

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
		$config['format'] = 'date_optional_time';
		return $config;
	}
}
