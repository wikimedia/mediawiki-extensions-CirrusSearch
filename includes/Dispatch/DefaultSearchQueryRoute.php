<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Search\SearchQuery;

/**
 * Defines the default route a query takes when no other route selects it.
 *
 * It is also referenced by cross-wiki search which always uses the
 * default query route.
 *
 * @see SearchQueryDispatchService::defaultProfileContext()
 * @license GPL-2.0-or-later
 */
class DefaultSearchQueryRoute implements SearchQueryRoute {
	private string $searchEngineEntryPoint;
	private string $profileContext;

	/**
	 * @param string $searchEngineEntryPoint
	 * @param string $profileContext
	 */
	public function __construct( string $searchEngineEntryPoint, string $profileContext ) {
		$this->searchEngineEntryPoint = $searchEngineEntryPoint;
		$this->profileContext = $profileContext;
	}

	/**
	 * Shouldn't be referenced, but is part of the interface.
	 *
	 * @param SearchQuery $query
	 * @return float
	 */
	public function score( SearchQuery $query ) {
		return 1.0;
	}

	/**
	 * @return string
	 */
	public function getSearchEngineEntryPoint(): string {
		return $this->searchEngineEntryPoint;
	}

	/**
	 * @return string
	 */
	public function getProfileContext(): string {
		return $this->profileContext;
	}
}
