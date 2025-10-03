<?php

namespace CirrusSearch\SecondTry;

class SecondTrySearchFactory {
	public static function build( string $strategy ): SecondTrySearch {
		switch ( $strategy ) {
			case 'georgian_transliteration':
				return new SecondTryGeorgianTransliteration();
			case 'russian_keyboard':
				return new SecondTryRussianKeyboard();
			case 'hebrew_keyboard':
				return new SecondTryHebrewKeyboard();
			default:
				throw new \InvalidArgumentException( "Unknown search strategy {$strategy}" );
		}
	}

}
