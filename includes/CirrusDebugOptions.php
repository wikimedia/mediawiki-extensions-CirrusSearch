<?php

namespace CirrusSearch;

use CirrusSearch\Query\SemanticSearchQueryBuilder;
use Elastica\Query;
use MediaWiki\Request\WebRequest;

/**
 * Cirrus debug options generally set via *unofficial* URI param (&cirrusXYZ=ZYX)
 */
class CirrusDebugOptions {

	/**
	 * @var bool
	 */
	private $cirrusDumpQuery = false;

	/**
	 * @var bool
	 */
	private $cirrusDumpQueryAST = false;

	/**
	 * @var bool
	 */
	private $cirrusDumpResult = false;

	private bool $cirrusSemanticSearch = false;

	private bool $cirrusSemanticSearchHighlights = false;

	/**
	 * @var string|null
	 */
	private $cirrusExplain;

	private ?string $cirrusExplainPage = null;

	/**
	 * @var string|null
	 */
	private $cirrusMLRModel;

	/**
	 * @var bool used by unit tests (to not die and return the query as json back to the caller)
	 */
	private $dumpAndDie = false;

	private function __construct() {
	}

	/**
	 * @param WebRequest $request
	 * @return self
	 */
	public static function fromRequest( WebRequest $request ): self {
		$options = new self();
		$options->cirrusDumpQuery = $request->getCheck( 'cirrusDumpQuery' );
		$options->cirrusDumpQueryAST = $request->getCheck( 'cirrusDumpQueryAST' );
		$options->cirrusDumpResult = $request->getCheck( 'cirrusDumpResult' );
		$options->cirrusSemanticSearch = $request->getCheck( 'cirrusSemanticSearch' );
		$options->cirrusSemanticSearchHighlights = $request->getVal( 'cirrusSemanticSearch' ) === 'hl';
		$options->cirrusExplain = self::debugOption( $request, 'cirrusExplain', [ 'verbose', 'pretty', 'hot', 'raw' ] );
		$options->cirrusExplainPage = $request->getVal( 'cirrusExplainPage' ) ?: null;
		$options->cirrusMLRModel = $request->getVal( 'cirrusMLRModel' );
		$options->dumpAndDie = $options->cirrusDumpQuery || $options->cirrusDumpQueryAST
			|| $options->cirrusDumpResult || $options->cirrusExplainPage !== null;
		return $options;
	}

	/**
	 * Default options (no debug options set)
	 */
	public static function defaultOptions(): self {
		return new self();
	}

	/**
	 * Dump the query but not die.
	 * Only useful in Unit tests.
	 */
	public static function forDumpingQueriesInUnitTests(): self {
		$options = new self();
		$options->cirrusDumpQuery = true;
		$options->dumpAndDie = false;
		return $options;
	}

	/**
	 * @param string|null $withExplain
	 * @return self
	 */
	public static function forRelevanceTesting( $withExplain = null ): self {
		$options = new self();
		$options->cirrusExplain = $withExplain;
		return $options;
	}

	public static function forSemanticSearchUnitTests(): self {
		$options = new self();
		$options->cirrusSemanticSearch = true;
		return $options;
	}

	/**
	 * Inspect the param names $param and return its value only
	 * if it belongs to the set of allowed values declared in $allowedValues
	 * @param WebRequest $request
	 * @param string $param
	 * @param string[] $allowedValues
	 * @return string|null the debug option or null
	 */
	private static function debugOption( WebRequest $request, $param, array $allowedValues ) {
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
	public function isCirrusDumpQuery() {
		return $this->cirrusDumpQuery;
	}

	/**
	 * @return bool
	 */
	public function isCirrusDumpQueryAST() {
		return $this->cirrusDumpQueryAST;
	}

	/**
	 * @return bool
	 */
	public function isCirrusDumpResult() {
		return $this->cirrusDumpResult;
	}

	public function isCirrusSemanticSearch(): bool {
		return $this->cirrusSemanticSearch;
	}

	public function isCirrusSemanticSearchHighlights(): bool {
		return $this->cirrusSemanticSearchHighlights;
	}

	/**
	 * @return string|null The local mediawiki page id to explain, or null when
	 *  this is not an explain-page request.
	 */
	public function getCirrusExplainPage() {
		return $this->cirrusExplainPage;
	}

	/**
	 * @return string|null The formatting to apply, or null to return raw explains
	 */
	public function getCirrusExplainFormat() {
		if ( $this->cirrusExplain === 'raw' || $this->cirrusDumpQuery || $this->cirrusDumpQueryAST
			|| $this->cirrusExplainPage !== null
		) {
			return null;
		}
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
		return $this->cirrusDumpQuery || $this->cirrusDumpQueryAST || $this->cirrusDumpResult
			|| $this->cirrusExplainPage !== null;
	}

	/**
	 * @param Query $query
	 * @return Query
	 */
	public function applyDebugOptions( Query $query ) {
		if ( $this->cirrusExplain !== null ) {
			$query->setExplain( true );
		}
		$stats = $query->hasParam( 'stats' ) ? $query->getParam( 'stats' ) : [];
		if ( $this->isCirrusSemanticSearchHighlights() && in_array( SemanticSearchQueryBuilder::SYNTAX_NAME, $stats ) ) {
			// only add the batch inference param if the semantic query is used (stats) & requested via cirrusSemanticSearch debug param.
			if ( $query->hasParam( 'ext' ) ) {
				$ext = $query->getParam( 'ext' );
				if ( !is_array( $ext ) ) {
					throw new \RuntimeException( 'Search ext parameter must be an array but got "' . gettype( $ext ) . '".' );
				}
				$ext['semantic_highlighting_batch'] = true;
			} else {
				$ext = [ 'semantic_highlighting_batch' => true ];
			}
			$query->setParam( 'ext', $ext );
		}
		return $query;
	}

	/**
	 * @return bool True when queries built with this set of debug options must
	 *  not have their results cached and returned to other users.
	 */
	public function mustNeverBeCached() {
		return $this->isReturnRaw() || $this->cirrusExplain !== null;
	}
}
