<?php

namespace CirrusSearch\SecondTry;

/**
 * Various methods to transform query strings for second-try searching.
 *
 * @license GPL-2.0-or-later
 */
trait SecondTrySearchTrait {
	/**
	 * Convert easier-to-read mapping strings into useful data structures.
	 *
	 * @param string $scr0 chars from keyboard #0
	 * @param string $scr1 corresponding chars from keyboard #1
	 * @param int $dirFrom mapping direction (0:0->1; 1:1->0; 2:both)
	 * @return array<int, array<string, string>>
	 */
	public static function stringToWrongKeyboardMaps( string $scr0, string $scr1,
			int $dirFrom = 2 ): array {
		$dwim = [];
		$dwim[0] = [];
		$dwim[1] = [];
		for ( $i = 0; $i < mb_strlen( $scr0 ); $i++ ) {
			$c1 = mb_substr( $scr0, $i, 1 );
			$c2 = mb_substr( $scr1, $i, 1 );
			if ( $dirFrom == 0 ) {
				$dwim[0][$c1] = $c2;
			} elseif ( $dirFrom == 1 ) {
				$dwim[1][$c2] = $c1;
			} else { // both
				$dwim[0][$c1] = $c2;
				$dwim[1][$c2] = $c1;
			}
		}
		return $dwim;
	}
}
