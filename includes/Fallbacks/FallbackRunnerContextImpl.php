<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\Parser\NamespacePrefixParser;
use CirrusSearch\Search\CirrusSearchResultSet;
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
	 * @var CirrusSearchResultSet (final)
	 */
	private $initialResultSet;

	/**
	 * The resultset as returned by the last call to FallbackMethod::rewrite()
	 * @var CirrusSearchResultSet (mutable)
	 */
	private $previousResultSet;

	/**
	 * @var ElasticaResultSet|null Response to elasticsearch request
	 *  issued by either ElasticSearchRequestFallbackMethod or
	 *  ElasticSearchSuggestFallbackMethod.
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
	 * @var NamespacePrefixParser
	 */
	private $namespacePrefixParser;
	/**
	 * @var CirrusSearchHookRunner
	 */
	private $cirrusSearchHookRunner;

	/**
	 * @param CirrusSearchResultSet $initialResultSet
	 * @param SearcherFactory $searcherFactory
	 * @param NamespacePrefixParser $namespacePrefixParser
	 * @param CirrusSearchHookRunner $cirrusSearchHookRunner
	 */
	public function __construct(
		CirrusSearchResultSet $initialResultSet,
		SearcherFactory $searcherFactory,
		NamespacePrefixParser $namespacePrefixParser,
		CirrusSearchHookRunner $cirrusSearchHookRunner
	) {
		$this->initialResultSet = $initialResultSet;
		$this->previousResultSet = $initialResultSet;
		$this->searcherFactory = $searcherFactory;
		$this->namespacePrefixParser = $namespacePrefixParser;
		$this->cirrusSearchHookRunner = $cirrusSearchHookRunner;
	}

	/**
	 * Initialize the previous resultset
	 * (only visible by FallbackRunner)
	 */
	public function setPreviousResultSet( CirrusSearchResultSet $previousResultSet ) {
		$this->previousResultSet = $previousResultSet;
	}

	public function resetSuggestResponse() {
		$this->suggestResponse = null;
	}

	public function setSuggestResponse( ElasticaResultSet $suggestResponse ) {
		$this->suggestResponse = $suggestResponse;
	}

	/**
	 * @inheritDoc
	 */
	public function hasMethodResponse() {
		return $this->suggestResponse !== null;
	}

	public function getInitialResultSet(): CirrusSearchResultSet {
		return $this->initialResultSet;
	}

	public function getPreviousResultSet(): CirrusSearchResultSet {
		return $this->previousResultSet;
	}

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

	public function getNamespacePrefixParser(): NamespacePrefixParser {
		return $this->namespacePrefixParser;
	}

	public function getCirrusSearchHookRunner(): CirrusSearchHookRunner {
		return $this->cirrusSearchHookRunner;
	}
}
