<?php

namespace CirrusSearch;

/**
 * Cirrus debug options generally set via *unofficial* URI param (&cirrusXYZ=ZYX)
 */
class CirrusDebugOptions {
	/**
	 * @var bool
	 */
	private $cirrusSuppressSuggest = false;

	/**
	 * @var string[]|null
	 */
	private $cirrusCompletionVariant;

	/**
	 * @var bool
	 */
	private $cirrusDumpQuery = false;

	/**
	 * @var bool
	 */
	private $cirrusDumpResult = false;

	/**
	 * @var string|null
	 */
	private $cirrusExplain;

	/**
	 * @var @var string|null
	 */
	private $cirrusMLRModel;

	/**
	 * @var bool used by unit tests (to not die and return the query as json back to the caller)
	 */
	private $dumpAndDie = false;

	private function __construct() {
	}

	/**
	 * @param \WebRequest $request
	 * @return CirrusDebugOptions
	 */
	public static function fromRequest( \WebRequest $request ) {
		$options = new self();
		$options->cirrusSuppressSuggest = self::debugFlag( $request, 'cirrusSuppressSuggest' );
		$options->cirrusCompletionVariant = $request->getArray( 'cirrusCompletionVariant' );
		$options->cirrusDumpQuery = self::debugFlag( $request, 'cirrusDumpQuery' );
		$options->cirrusDumpResult = self::debugFlag( $request, 'cirrusDumpResult' );
		$options->cirrusExplain = self::debugOption( $request, 'cirrusExplain', [ 'verbose', 'pretty', 'hot' ] );
		$options->cirrusMLRModel = $request->getVal( 'cirrusMLRModel' );
		$options->dumpAndDie = $options->cirrusDumpQuery || $options->cirrusDumpResult;
		return $options;
	}

	/**
	 * Default options (no debug options set)
	 * @return CirrusDebugOptions
	 */
	public static function defaultOptions() {
		return new self();
	}

	/**
	 * Dump the query but not die.
	 * Only useful in Unit tests.
	 * @return CirrusDebugOptions
	 */
	public static function forDumpingQueriesInUnitTests() {
		$options = new self();
		$options->cirrusDumpQuery = true;
		$options->dumpAndDie = false;
		return $options;
	}

	/**
	 * Inspect the param named $param and return true if set
	 * false otherwise
	 * @param \WebRequest $request
	 * @param string $param
	 * @return bool true if the param is set, null in all other cases
	 */
	private static function debugFlag( \WebRequest $request, $param ) {
		return $request->getVal( $param ) !== null;
	}

	/**
	 * Inspect the param names $param and return its value only
	 * if it belongs to the set of allowed values declared in $allowedValues
	 * @param \WebRequest $request
	 * @param string $param
	 * @param string[] $allowedValues
	 * @return string|null the debug option or null
	 */
	private static function debugOption( \WebRequest $request, $param, array $allowedValues ) {
		$val = $request->getVal( $param );
		if ( $val === null ) {
			return null;
		}
		if ( in_array( $val, $allowedValues ) ) {
			return $val;
		}
		return null;
	}

	/**
	 * @return bool
	 */
	public function isCirrusSuppressSuggest() {
		return $this->cirrusSuppressSuggest;
	}

	/**
	 * @return null|string[]
	 */
	public function getCirrusCompletionVariant() {
		return $this->cirrusCompletionVariant;
	}

	/**
	 * @return bool
	 */
	public function isCirrusDumpQuery() {
		return $this->cirrusDumpQuery;
	}

	/**
	 * @return bool
	 */
	public function isCirrusDumpResult() {
		return $this->cirrusDumpResult;
	}

	/**
	 * @return string|null
	 */
	public function getCirrusExplain() {
		return $this->cirrusExplain;
	}

	/**
	 * @return string|null
	 */
	public function getCirrusMLRModel() {
		return $this->cirrusMLRModel;
	}

	/**
	 * @return bool
	 */
	public function isDumpAndDie() {
		return $this->dumpAndDie;
	}

	/**
	 * @return bool true if raw data (query or results) needs to be returned
	 */
	public function isReturnRaw() {
		return $this->cirrusDumpQuery || $this->cirrusDumpResult;
	}
}
