<?php

namespace CirrusSearch\LanguageDetector;

use CirrusSearch\SearchConfig;

/**
 * Interface for a language detector class
 */
interface Detector {

	/**
	 * @param SearchConfig $config
	 * @param \WebRequest $request
	 * @return Detector
	 */
	public static function build( SearchConfig $config, \WebRequest $request );

	/**
	 * Detect language
	 *
	 * @param string $text Text to detect language
	 * @return string|null Preferred language, or null if none found
	 */
	public function detect( $text );
}
