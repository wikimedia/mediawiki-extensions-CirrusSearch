<?php

namespace CirrusSearch\SecondTry;

use Transliterator;
use Wikimedia\Assert\Assert;

/**
 * Simple strategy that uses a PHP Transliterator to mimic icu_folding as performed by the lucene icu
 * analysis components.
 */
class SecondTryICUFolding implements SecondTrySearch {
	private Transliterator $normalizer;

	public static function build( array $config ): self {
		$normalizer = null;
		$method = self::getMethodFromConfig( $config );
		if ( $method === 'naive' ) {
			$normalizer = \Transliterator::createFromRules(
				'::NFD;::Upper;::Lower;::[:Nonspacing Mark:] Remove;::NFC;[\_\-\'\u2019\u02BC]>\u0020;'
			);
		} elseif ( $method === 'utr30' ) {
			$normalizer ??= \Transliterator::createFromRules( file_get_contents( __DIR__ . '/../../data/utr30.txt' ) );
		}
		Assert::postcondition( $normalizer !== null,
			'Failed to load SecondTryICUFolding with method ' . $method );
		return new self( $normalizer );
	}

	public static function getCacheKey( array $config ): string {
		return self::class . ":" . self::getMethodFromConfig( $config );
	}

	private static function getMethodFromConfig( array $config ): string {
		return $config['method'] ?? 'naive';
	}

	public function __construct( Transliterator $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * @inheritDoc
	 */
	public function candidates( string $searchQuery ): array {
		$folded = $this->normalizer->transliterate( $searchQuery );
		if ( $folded === '' || $folded === $searchQuery ) {
			return [];
		}
		return [ $folded ];
	}
}
