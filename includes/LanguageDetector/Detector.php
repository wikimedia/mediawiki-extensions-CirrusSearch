<?php

namespace CirrusSearch\LanguageDetector;

use CirrusSearch;

/**
 * Interface for a language detector class
 */
interface Detector {
	/**
	 * Detect language
	 *
	 * @param CirrusSearch $cirrus Searching class
	 * @param string $text Text to detect language
	 * @return string|null Preferred language, or null if none found
	 */
	public function detect( CirrusSearch $cirrus, $text );
}
