<?php

namespace CirrusSearch\Sanity;

use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;

/**
 * A remediator that simply records all actions scheduled to it.
 * These actions can then be replayed on an arbitrary remediator by calling replayOn( Remediator ).
 * The actions can be reset by calling resetActions()
 */
class BufferedRemediator implements Remediator {
	/** @var array[] */
	private $actions = [];

	/**
	 * @inheritDoc
	 */
	public function redirectInIndex( string $docId, WikiPage $page, string $indexSuffix ) {
		$this->actions[] = [ __FUNCTION__, func_get_args() ];
	}

	/**
	 * @inheritDoc
	 */
	public function pageNotInIndex( WikiPage $page ) {
		$this->actions[] = [ __FUNCTION__, func_get_args() ];
	}

	/**
	 * @inheritDoc
	 */
	public function ghostPageInIndex( $docId, Title $title ) {
		$this->actions[] = [ __FUNCTION__, func_get_args() ];
	}

	/**
	 * @inheritDoc
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexSuffix ) {
		$this->actions[] = [ __FUNCTION__, func_get_args() ];
	}

	/**
	 * @inheritDoc
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexSuffix ) {
		$this->actions[] = [ __FUNCTION__, func_get_args() ];
	}

	/**
	 * @inheritDoc
	 */
	public function oldDocument( WikiPage $page ) {
		$this->actions[] = [ __FUNCTION__, func_get_args() ];
	}

	/**
	 * The list of recorded actions
	 * @return array
	 */
	public function getActions() {
		return $this->actions;
	}

	/**
	 * Check if the actions recorded on this remediator are the same
	 * as the actions recorded on $remediator.
	 * @param BufferedRemediator $remediator
	 * @return bool
	 */
	public function hasSameActions( BufferedRemediator $remediator ) {
		return $this->actions === $remediator->actions;
	}

	public function replayOn( Remediator $remediator ) {
		foreach ( $this->actions as [ $method, $args ] ) {
			$remediator->$method( ...$args );
		}
	}

	/**
	 * Reset actions recorded by this remediator
	 */
	public function resetActions() {
		$this->actions = [];
	}
}
