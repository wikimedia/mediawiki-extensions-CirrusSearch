<?php

namespace CirrusSearch\SecondTry;

use MediaWiki\MediaWikiServices;

class SecondTrySearchFactory {
	public static function build( string $strategy, MediaWikiServices $mediaWikiServices, array $config ): SecondTrySearch {
		switch ( $strategy ) {
			case 'georgian_transliteration':
				return new SecondTryGeorgianTransliteration();
			case 'russian_keyboard':
				return new SecondTryRussianKeyboard();
			case 'hebrew_keyboard':
				return new SecondTryHebrewKeyboard();
			case 'language_converter':
				return SecondTryLanguageConverter::build(
					$mediaWikiServices->getLanguageConverterFactory()->getLanguageConverter(),
					$config
				);
			default:
				throw new \InvalidArgumentException( "Unknown search strategy {$strategy}" );
		}
	}

}
