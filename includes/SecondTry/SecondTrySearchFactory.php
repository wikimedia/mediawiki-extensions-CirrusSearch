<?php

namespace CirrusSearch\SecondTry;

use MediaWiki\Language\LanguageConverterFactory;

class SecondTrySearchFactory {
	public const SERVICE = self::class;

	/** @var array<string, SecondTrySearch> */
	private array $cache = [];

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
				return $this->getSecondTrySearch(
					SecondTryGeorgianTransliteration::class,
					static fn (): SecondTrySearch => new SecondTryGeorgianTransliteration()
				);
			case 'hindi_transliteration':
				return $this->getSecondTrySearch(
					SecondTryHindiTransliteration::class,
					static fn (): SecondTrySearch => new SecondTryHindiTransliteration()
				);
			case 'russian_keyboard':
				return $this->getSecondTrySearch(
					SecondTryRussianKeyboard::cacheKey( $config ),
					static fn (): SecondTrySearch => SecondTryRussianKeyboard::build( $config )
				);
			case 'hebrew_keyboard':
				return $this->getSecondTrySearch(
					SecondTryHebrewKeyboard::cacheKey( $config ),
					static fn (): SecondTrySearch => SecondTryHebrewKeyboard::build( $config )
				);
			case 'language_converter':
				if ( $this->languageConverterFactory !== null ) {
					return $this->getSecondTrySearch(
						SecondTryLanguageConverter::class,
						fn () => SecondTryLanguageConverter::build(
							$this->languageConverterFactory->getLanguageConverter(),
							$config
						)
					);
				}
				throw new \InvalidArgumentException(
					"Strategy {$strategy} requires languageConverterFactory initialization" );
			default:
				throw new \InvalidArgumentException( "Unknown search strategy {$strategy}" );
		}
	}

	/**
	 * @param string $key
	 * @param callable(): SecondTrySearch $factory
	 * @return SecondTrySearch
	 */
	private function getSecondTrySearch( string $key, callable $factory ): SecondTrySearch {
		$runner = $this->cache[$key] ?? null;
		if ( $runner === null ) {
			$runner = $factory();
			$this->cache[$key] = $runner;
		}
		return $runner;
	}
}
