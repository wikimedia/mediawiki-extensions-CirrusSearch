<?php

namespace CirrusSearch\Search;

/**
 * Index field representing integer.
 * @package CirrusSearch
 */
class IntegerIndexField extends CirrusIndexField {
	/** @inheritDoc */
	protected $typeName = 'long';
}
