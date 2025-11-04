<?php

namespace CirrusSearch\SecondTry;

use MediaWiki\Language\LanguageConverterFactory;

class SecondTrySearchFactory {
	private ?LanguageConverterFactory $languageConverterFactory;

	/**
	 * @param ?LanguageConverterFactory $languageConverterFactory the converter to use;
	 *        if null then 'language_converter' strategy will be unavailable.
	 */
	public function __construct( ?LanguageConverterFactory $languageConverterFactory = null ) {
		$this->languageConverterFactory = $languageConverterFactory;
	}

	public function build( string $strategy, array $config ): SecondTrySearch {
		switch ( $strategy ) {
			case 'georgian_transliteration':
				return new SecondTryGeorgianTransliteration();
			case 'russian_keyboard':
				return SecondTryRussianKeyboard::build( $config );
			case 'hebrew_keyboard':
				return SecondTryHebrewKeyboard::build( $config );
			case 'language_converter':
				if ( $this->languageConverterFactory !== null ) {
					return SecondTryLanguageConverter::build(
						$this->languageConverterFactory->getLanguageConverter(),
						$config
					);
				}
				throw new \InvalidArgumentException(
					"Strategy {$strategy} requires languageConverterFactory initialization" );
			default:
				throw new \InvalidArgumentException( "Unknown search strategy {$strategy}" );
		}
	}
}
