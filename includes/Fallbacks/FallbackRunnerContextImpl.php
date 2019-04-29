<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;
use Elastica\ResultSet as ElasticaResultSet;
use Wikimedia\Assert\Assert;

/**
 * Basic implementation of a FallbackRunnerContext.
 * Should only be visible by FallbackRunner as its states should be closely
 * maintained by the FallbackRunner.
 */
class FallbackRunnerContextImpl implements FallbackRunnerContext {
	/**
	 * Initial ResultSet as returned by the main search query
	 * @var ResultSet (final)
	 */
	private $initialResultSet;

	/**
	 * The resultset as returned by the last call to FallbackMethod::rewrite()
	 * @var ResultSet (mutable)
	 */
	private $previousResultSet;

	/**
	 * @var ElasticaResultSet|null
	 */
	private $suggestResponse;

	/**
	 * FallbackRunnerContextImpl constructor.
	 * @param ResultSet $initialResultSet
	 */
	public function __construct( ResultSet $initialResultSet ) {
		$this->initialResultSet = $initialResultSet;
		$this->previousResultSet = $initialResultSet;
	}

	/**
	 * Initialize the previous resultset
	 * (only visible by FallbackRunner)
	 * @param ResultSet $previousResultSet
	 */
	public function setPreviousResultSet( ResultSet $previousResultSet ) {
		$this->previousResultSet = $previousResultSet;
	}

	public function resetSuggestResponse() {
		$this->suggestResponse = null;
	}

	/**
	 * @param ElasticaResultSet $suggestResponse
	 */
	public function setSuggestResponse( ElasticaResultSet $suggestResponse ) {
		$this->suggestResponse = $suggestResponse;
	}

	/**
	 * @return ResultSet
	 */
	public function getInitialResultSet() {
		return $this->initialResultSet;
	}

	/**
	 * @return ResultSet
	 */
	public function getPreviousResultSet() {
		return $this->previousResultSet;
	}

	/**
	 * @return ElasticaResultSet
	 */
	public function getMethodResponse(): ElasticaResultSet {
		Assert::precondition( $this->suggestResponse !== null, 'Must have a resultset set' );
		return $this->suggestResponse;
	}
}
