<?php

namespace CirrusSearch\Parser\AST;

/**
 * Parsed query
 */
class ParsedQuery {

	/**
	 * markup to indicate that the query was cleaned up
	 * detecting a double quote used as a gershayim
	 * see T66350
	 */
	const CLEANUP_GERSHAYIM_QUIRKS = 'gershayim_quirks';

	/**
	 * markup to indicate that the had some question marks
	 * stripped
	 * @see \CirrusSearch\Util::stripQuestionMarks
	 */
	const CLEANUP_QMARK_STRIPPING = 'stripped_qmark';

	/**
	 * @var ParsedNode
	 */
	private $root;

	/**
	 * @var string
	 */
	private $query;

	/**
	 * @var string
	 */
	private $rawQuery;

	/**
	 * @var bool[] indexed by cleanup type
	 */
	private $queryCleanups;

	/**
	 * @var ParseWarning[]
	 */
	private $parseWarnings;

	/**
	 * ParsedQuery constructor.
	 * @param ParsedNode $root
	 * @param string $query cleaned up query string
	 * @param string $rawQuery original query as received by the search engine
	 * @param bool[] $queryCleanups indexed by cleanup type (non-empty when $query !== $rawQuery)
	 * @param ParseWarning[] $parseWarnings list of warnings detected during parsing
	 */
	public function __construct( ParsedNode $root, $query, $rawQuery, $queryCleanups, array $parseWarnings = [] ) {
		$this->root = $root;
		$this->query = $query;
		$this->rawQuery = $rawQuery;
		$this->queryCleanups = $queryCleanups;
		$this->parseWarnings = $parseWarnings;
	}

	/**
	 * @return ParsedNode
	 */
	public function getRoot() {
		return $this->root;
	}

	/**
	 * The query being parsed
	 * Some cleanups may have been made to the raw query
	 * @return string
	 */
	public function getQuery() {
		return $this->query;
	}

	/**
	 * The raw query as received by the search engine
	 * @return string
	 */
	public function getRawQuery() {
		return $this->rawQuery;
	}

	/**
	 * Check if the query was cleanup with this type
	 * @see ParsedQuery::CLEANUP_QMARK_STRIPPING
	 * @see ParsedQuery::CLEANUP_GERSHAYIM_QUIRKS
	 * @param string $cleanup
	 * @return bool
	 */
	public function hasCleanup( $cleanup ) {
		return isset( $this->queryCleanups[$cleanup] );
	}

	/**
	 * List of warnings detected at parse time
	 * @return ParseWarning[]
	 */
	public function getParseWarnings() {
		return $this->parseWarnings;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		$ar = [
			'query' => $this->query,
			'rawQuery' => $this->rawQuery
		];
		if ( !empty( $this->queryCleanups ) ) {
			$ar['queryCleanups'] = $this->queryCleanups;
		}
		if ( !empty( $this->parseWarnings ) ) {
			$ar['warnings'] = array_map( function ( ParseWarning $w ) {
				return $w->toArray();
			}, $this->parseWarnings );
		}
		$ar['root'] = $this->getRoot()->toArray();
		return $ar;
	}
}
