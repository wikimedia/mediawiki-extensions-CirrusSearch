<?php

namespace CirrusSearch\Jenkins;

/**
 * Deterministic stand in for the LiftWing models in the semantic integration tests.
 *
 * The passage vectors written at index time and the LiftWing stub both use it, so the
 * document vectors and the query vectors always agree. It's a port of the fake inference
 * in the semantic failure sim harness. The stub loads it outside of MediaWiki, so it must
 * not depend on anything else.
 *
 * @license GPL-2.0-or-later
 */
class FakeInference {
	public const DIMENSIONS = 32;

	/**
	 * Each lowercased word token is hashed into one slot with a hash derived sign, and the
	 * result is normalized to unit length. Vectors rank by the words they share, which is
	 * enough when fixture pages use distinctive words.
	 *
	 * @param string $text
	 * @return float[]
	 */
	public static function embed( string $text ): array {
		$vector = array_fill( 0, self::DIMENSIONS, 0.0 );
		preg_match_all( '/\w+/u', mb_strtolower( $text ), $matches );
		foreach ( $matches[0] as $token ) {
			$hash = hash( 'sha256', $token, true );
			$vector[ord( $hash[0] ) % self::DIMENSIONS] += ( ord( $hash[1] ) & 1 ) ? 1.0 : -1.0;
		}
		$norm = sqrt( array_sum( array_map( static fn ( $v ) => $v * $v, $vector ) ) ) ?: 1.0;
		return array_map( static fn ( $v ) => $v / $norm, $vector );
	}
}
