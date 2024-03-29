<?php

namespace CirrusSearch\Search;

use LogicException;

class EmptySearchResultSet extends BaseCirrusSearchResultSet {
	/** @var bool */
	private $searchContainedSyntax;

	/**
	 * @param bool $searchContainedSyntax
	 */
	public function __construct( $searchContainedSyntax ) {
		$this->searchContainedSyntax = $searchContainedSyntax;
	}

	/**
	 * @inheritDoc
	 */
	protected function transformOneResult( \Elastica\Result $result ) {
		// @phan-suppress-previous-line PhanPluginNeverReturnMethod
		throw new LogicException( "An empty ResultSet has nothing to transform" );
	}

	/**
	 * @inheritDoc
	 */
	public function getElasticaResultSet() {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function searchContainedSyntax() {
		return $this->searchContainedSyntax;
	}
}
