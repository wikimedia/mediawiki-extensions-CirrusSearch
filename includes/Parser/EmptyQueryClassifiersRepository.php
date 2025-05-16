<?php declare( strict_types=1 );

namespace CirrusSearch\Parser;

use CirrusSearch\SearchConfig;
use RuntimeException;

/**
 * @license GPL-2.0-or-later
 */
class EmptyQueryClassifiersRepository implements ParsedQueryClassifiersRepository {

	/** @inheritDoc	*/
	public function registerClassifier( ParsedQueryClassifier $classifier ): void {
	}

	/** @inheritDoc	*/
	public function registerClassifierAsCallable( array $classes, callable $callable ): void {
	}

	/**
	 * @inheritDoc
	 * @return never
	 */
	public function getConfig(): SearchConfig {
		throw new RuntimeException( self::class . ' does not implement getConfig()' );
	}

	/**
	 * @inheritDoc
	 * @return never
	 */
	public function getClassifier( $name ) {
		throw new ParsedQueryClassifierException( "Classifier {name} not found", [ 'name' => $name ] );
	}

	/** @inheritDoc	*/
	public function getKnownClassifiers(): array {
		return [];
	}

}
