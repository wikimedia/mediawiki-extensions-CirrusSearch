<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Searcher;
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
	 * @var SearcherFactory
	 */
	private $searcherFactory;

	/**
	 * @var bool
	 */
	private $canMakeCostlyCall = true;

	/**
	 * FallbackRunnerContextImpl constructor.
	 * @param ResultSet $initialResultSet
	 * @param SearcherFactory $searcherFactory
	 */
	public function __construct( ResultSet $initialResultSet, SearcherFactory $searcherFactory ) {
		$this->initialResultSet = $initialResultSet;
		$this->previousResultSet = $initialResultSet;
		$this->searcherFactory = $searcherFactory;
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

	/**
	 * @return bool
	 */
	public function costlyCallAllowed() {
		return $this->canMakeCostlyCall;
	}

	/**
	 * @param \CirrusSearch\Search\SearchQuery $rewrittenQuery
	 * @return Searcher
	 */
	public function makeSearcher( SearchQuery $rewrittenQuery ): Searcher {
		Assert::precondition( $this->canMakeCostlyCall,
			'Costly calls are no longer accepted, check costlyCallAllowed before calling makeSearcher' );
		// For now we just allow a single call, we might prefer a time constrained approach
		// So that multiple calls can be made if we still have some processing time left.
		$this->canMakeCostlyCall = false;
		return $this->searcherFactory->makeSearcher( $rewrittenQuery );
	}
}