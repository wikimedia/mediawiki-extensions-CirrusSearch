<?php

namespace CirrusSearch\Sanity;

use MediaWiki\Title\Title;
use WikiPage;

/**
 * Counts problems seen and delegates remediation to another instance.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

class CountingRemediator implements Remediator {

	/** @var Remediator Instance to delgate to */
	private Remediator $delegate;

	/**
	 * @var callable Function with no arguments returning a
	 *  CounterMetric instance. This must be a callable, and
	 *  not the CounterMetric itself, as the api requires setting
	 *  all labels for each increment.
	 */
	private $counterFactory;

	/**
	 * @param Remediator $delegate Instance to delgate to
	 * @param callable $counterFactory Function with a single string argument returning a
	 *  CounterMetric instance.
	 */
	public function __construct( Remediator $delegate, callable $counterFactory ) {
		$this->delegate = $delegate;
		$this->counterFactory = $counterFactory;
	}

	private function increment( string $problem ) {
		( $this->counterFactory )( $problem )->increment();
	}

	/**
	 * @inheritDoc
	 */
	public function redirectInIndex( string $docId, WikiPage $page, string $indexSuffix ) {
		$this->increment( __FUNCTION__ );
		$this->delegate->redirectInIndex( $docId, $page, $indexSuffix );
	}

	/**
	 * @inheritDoc
	 */
	public function pageNotInIndex( WikiPage $page ) {
		$this->increment( __FUNCTION__ );
		$this->delegate->pageNotInIndex( $page );
	}

	/**
	 * @inheritDoc
	 */
	public function ghostPageInIndex( $docId, Title $title ) {
		$this->increment( __FUNCTION__ );
		$this->delegate->ghostPageInIndex( $docId, $title );
	}

	/**
	 * @inheritDoc
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexSuffix ) {
		$this->increment( __FUNCTION__ );
		$this->delegate->pageInWrongIndex( $docId, $page, $indexSuffix );
	}

	/**
	 * @inheritDoc
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexSuffix ) {
		$this->increment( __FUNCTION__ );
		$this->delegate->oldVersionInIndex( $docId, $page, $indexSuffix );
	}

	/**
	 * @inheritDoc
	 */
	public function oldDocument( WikiPage $page ) {
		$this->increment( __FUNCTION__ );
		$this->delegate->oldDocument( $page );
	}
}
