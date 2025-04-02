<?php

namespace CirrusSearch\Iterator;

use Iterator;

/**
 * Allows extending classes to decorate an Iterator with
 * reduced boilerplate.
 */
abstract class IteratorDecorator implements Iterator {
	protected Iterator $iterator;

	public function __construct( Iterator $iterator ) {
		$this->iterator = $iterator;
	}

	/** @return mixed */
	#[\ReturnTypeWillChange]
	public function current() {
		return $this->iterator->current();
	}

	/** @return mixed */
	#[\ReturnTypeWillChange]
	public function key() {
		return $this->iterator->key();
	}

	public function next(): void {
		$this->iterator->next();
	}

	public function rewind(): void {
		$this->iterator->rewind();
	}

	public function valid(): bool {
		return $this->iterator->valid();
	}
}
