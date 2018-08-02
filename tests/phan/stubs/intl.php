<?php

class Transliterator {
	const FORWARD = 0;
	const REVERSE = 1;

	public $id;

	public function __construct() {
	}

	/**
	 * @param string $id
	 * @param int $direction
	 * @return Transliterator
	 */
	public static function create( $id, $direction = 0 ) {
	}

	/**
	 * @param string $id
	 * @param int $direction
	 * @return Transliterator
	 */
	public static function createFromRules( $rules, $direction = 0 ) {
	}

	/**
	 * @return Transliterator
	 */
	public function createInverse() {
	}

	/**
	 * @return int
	 */
	public function getErrorCode() {
	}

	/**
	 * @return string
	 */
	public function getErorMessage() {
	}

	/**
	 * @return array
	 */
	public static function listIDs() {
	}

	/**
	 * @param string $subject
	 * @param int $start
	 * @param int $end
	 * @return string
	 */
	public function transliterate( $subject, $start = 0, $end = -1 ) {
	}
}
