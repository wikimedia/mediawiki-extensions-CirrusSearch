<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Profile\SearchProfileException;
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
	/** Profile key naming the profile context this route selects. */
	public const ENTRY_CONTEXT = 'context';

	private string $name;
	private string $searchEngineEntryPoint;
	private string $profileContext;

	/**
	 * @param string $name name this route takes in the dispatch table
	 * @param string $searchEngineEntryPoint
	 * @param string $profileContext
	 */
	public function __construct(
		string $name,
		string $searchEngineEntryPoint,
		string $profileContext
	) {
		$this->name = $name;
		$this->searchEngineEntryPoint = $searchEngineEntryPoint;
		$this->profileContext = $profileContext;
	}

	/**
	 * Build the default route out of its entry in a dispatch profile.
	 *
	 * @param string $searchEngineEntryPoint
	 * @param string $name key the entry has in the profile
	 * @param array $entry the entry
	 * @return self
	 * @throws SearchProfileException when the entry is malformed
	 */
	public static function fromProfileEntry(
		string $searchEngineEntryPoint,
		string $name,
		array $entry
	): self {
		if ( !isset( $entry[self::ENTRY_CONTEXT] ) ) {
			throw new SearchProfileException( "Invalid default route $name: missing 'context'" );
		}
		return new self( $name, $searchEngineEntryPoint, $entry[self::ENTRY_CONTEXT] );
	}

	/**
	 * Default route always accepts.
	 *
	 * @param SearchQuery $query
	 * @return RouteDecision
	 */
	public function decide( SearchQuery $query ): RouteDecision {
		return new RouteDecision( $this->profileContext, true, RouteDecision::REASON_DEFAULT, 1.0 );
	}

	public function getName(): string {
		return $this->name;
	}

	public function getSearchEngineEntryPoint(): string {
		return $this->searchEngineEntryPoint;
	}

	public function getProfileContext(): string {
		return $this->profileContext;
	}
}
