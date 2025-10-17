<?php

namespace CirrusSearch\SecondTry;

use MediaWiki\Language\LanguageConverterFactory;

class SecondTrySearchFactory {
	private LanguageConverterFactory $languageConverterFactory;

	public function __construct( LanguageConverterFactory $languageConverterFactory ) {
		$this->languageConverterFactory = $languageConverterFactory;
	}

	public function build( string $strategy, array $config ): SecondTrySearch {
		switch ( $strategy ) {
			case 'georgian_transliteration':
				return new SecondTryGeorgianTransliteration();
			case 'russian_keyboard':
				return new SecondTryRussianKeyboard();
			case 'hebrew_keyboard':
				return new SecondTryHebrewKeyboard();
			case 'language_converter':
				return SecondTryLanguageConverter::build(
					$this->languageConverterFactory->getLanguageConverter(),
					$config
				);
			default:
				throw new \InvalidArgumentException( "Unknown search strategy {$strategy}" );
		}
	}
}
