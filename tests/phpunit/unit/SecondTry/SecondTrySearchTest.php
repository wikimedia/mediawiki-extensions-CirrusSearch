<?php

namespace CirrusSearch\SecondTry;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Language\ILanguageConverter;
use MediaWiki\Language\LanguageConverterFactory;

/**
 * Test escaping search strings.
 *
 * @license GPL-2.0-or-later
 *
 * @covers \CirrusSearch\SecondTry\SecondTryHebrewKeyboard
 * @covers \CirrusSearch\SecondTry\SecondTryRussianKeyboard
 * @covers \CirrusSearch\SecondTry\SecondTryGeorgianTransliteration
 * @group CirrusSearch
 */
class SecondTrySearchTest extends CirrusTestCase {
	public static function provideTestSecondTry(): \Generator {
		// hebrew_keyboard
		yield 'he to qwerty' => [ 'hebrew_keyboard', 'מםא חודא עןננקרןדי', [ 'not just gibberish' ] ];
		yield 'he qwerty to he lower' => [ 'hebrew_keyboard', 'ndsk thhpk', [ 'מגדל אייפל' ] ];
		yield 'he qwerty to he upper' => [ 'hebrew_keyboard', 'NDSK THHPK', [ 'מגדל אייפל' ] ];
		yield 'he mixed scripts' => [ 'hebrew_keyboard', 'מםא חודא עןננקרןדי NDSK thhpk',
								 [ 'not just gibberish מגדל אייפל' ] ];
		yield 'he mixed case' => [ 'hebrew_keyboard', 'MבMשמדןםמ', [ 'McMansion' ] ];
		yield 'he nothing relevant' => [ 'hebrew_keyboard', 'ウィキペディア ᐅᐃᑭᐱᑎᐊ', [] ]; // Japanese, Inuktitut

		// russian_keyboard
		yield 'ru to qwerty' => [ 'russian_keyboard', 'тще огые пшииукшыр', [ 'not just gibberish' ] ];
		yield 'ru qwerty to ru lower' => [ 'russian_keyboard', 'qatktdf ,fiyz', [ 'йфелева башня' ] ];
		yield 'ru qwerty to ru upper' => [ 'russian_keyboard', 'QATKTDF <FIYZ', [ 'ЙФЕЛЕВА БАШНЯ' ] ];
		yield 'ru all punctuation to ru' => [ 'russian_keyboard', ':\'[\' :\',\'[\' <\'.', [ 'Жэхэ Жэбэхэ Бэю' ] ];
		yield 'ru mixed scripts' => [ 'russian_keyboard', 'qatktdf ,fiyz тще огые пшииукшыр',
				[ 'йфелева башня not just gibberish' ] ];
		yield 'ru nothing relevant' => [ 'russian_keyboard', 'ウィキペディア ᐅᐃᑭᐱᑎᐊ', [] ]; // Japanese, Inuktitut

		// georgian_transliteration
		yield 'geo_tr mixed-case latin to geo' => [ 'georgian_transliteration', 'saqarTvelos istoria', [ 'საქართველოს ისტორია' ] ];
		yield 'geo_tr latin with digraphs' => [ 'georgian_transliteration', 'chubinashvili', [ 'ჩუბინაშვილი' ] ];
		yield 'geo_tr first letter capitalized latin' => [ 'georgian_transliteration', 'beroshvili Beroshvili', [ 'ბეროშვილი ბეროშვილი' ] ];
		yield 'geo_tr unmappable latin 1' => [ 'georgian_transliteration', 'été', [] ];
		yield 'geo_tr unmappable latin 2' => [ 'georgian_transliteration', 'ʃəzæm', [] ];
		yield 'geo_tr overlapping latin diagraphs 1' => [ 'georgian_transliteration',
														  'hatsha HATSHA HaTsha', [ 'ჰაცჰა ჰაცჰა ჰაცჰა' ] ]; // ts + h
		yield 'geo_tr overlapping latin diagraphs 2' => [ 'georgian_transliteration', 'hatSha', [ 'ჰატშა' ] ]; // t + sh
		yield 'geo_tr simple cyrillic' => [ 'georgian_transliteration', 'кавазашвили', [ 'კავაზაშვილი' ] ];
		yield 'geo_tr cyrillic with diacritics' => [ 'georgian_transliteration', 'Кавазашви́ли', [ 'კავაზაშვილი' ] ];
		yield 'geo_tr cyrillic digraphs' => [ 'georgian_transliteration', 'Ягор Абашидзе', [ 'იაგორ აბაშიძე' ] ];
		yield 'geo_tr unmappable cyrillic 1' => [ 'georgian_transliteration', 'щёлк', [] ];
		yield 'geo_tr unmappable cyrillic 2' => [ 'georgian_transliteration', 'Аҧсуа', [] ];
		yield 'geo_tr contextual cyrillic' => [ 'georgian_transliteration',
												'трото тлатла ппп ттт', [ 'ტროტო თლათლა ფფპ თტთ' ] ]; // nonsense syllables
		yield 'geo_tr mixed latin + cyrillic' => [ 'georgian_transliteration', 'Дaviд', [ 'დავიდ' ] ]; // why? people are weird
		yield 'geo_tr ignore georgian' => [ 'georgian_transliteration', 'ვიკიპედია', [] ];
		yield 'geo_tr ignore mixed georgian' => [ 'georgian_transliteration', 'ვიკიპედია Wikipedia Википедия', [] ];
		yield 'geo_tr nothing relevant' => [ 'georgian_transliteration', 'ウィキペディア ᐅᐃᑭᐱᑎᐊ', [] ]; // Japanese, Inuktitut
	}

	/**
	 * @dataProvider provideTestSecondTry
	 */
	public function testSecondTry( string $strategy, string $input, array $expected ): void {
		$secondTryFactory = new SecondTrySearchFactory( $this->createMock( LanguageConverterFactory::class ) );
		$transformer = $secondTryFactory->build( $strategy, [] );
		$this->assertEquals( $expected, $transformer->candidates( $input ) );
	}

	public static function provideTestOneWayDwim(): \Generator {
		// hebrew_keyboard
		yield 'he-h2q' => [ 'hebrew_keyboard', 'h2q', 'עןננקרןדי NDSK', [ 'gibberish NDSK' ] ];
		yield 'he-h2l' => [ 'hebrew_keyboard', 'h2l', 'עןננקרןדי NDSK', [ 'gibberish NDSK' ] ];
		yield 'he-q2h' => [ 'hebrew_keyboard', 'q2h', 'עןננקרןדי NDSK', [ 'עןננקרןדי מגדל' ] ];
		yield 'he-l2h' => [ 'hebrew_keyboard', 'l2h', 'עןננקרןדי NDSK', [ 'עןננקרןדי מגדל' ] ];
		yield 'he-both' => [ 'hebrew_keyboard', 'both', 'עןננקרןדי NDSK', [ 'gibberish מגדל' ] ];

		yield 'he mixed case-h2q' => [ 'hebrew_keyboard', 'h2q', 'MבMשמדןםמ', [ 'McMansion' ] ];
		yield 'he mixed case-q2h' => [ 'hebrew_keyboard', 'q2h', 'MבMשמדןםמ', [] ];
		yield 'he mixed case-both' => [ 'hebrew_keyboard', 'both', 'MבMשמדןםמ', [ 'McMansion' ] ];

		// russian_keyboard
		yield 'ru-r2q' => [ 'russian_keyboard', 'r2q', ',fiyz пшииукшыр', [ ',fiyz gibberish' ] ];
		yield 'ru-c2l' => [ 'russian_keyboard', 'c2l', ',fiyz пшииукшыр', [ ',fiyz gibberish' ] ];
		yield 'ru-q2r' => [ 'russian_keyboard', 'q2r', ',fiyz пшииукшыр', [ 'башня пшииукшыр' ] ];
		yield 'ru-l2c' => [ 'russian_keyboard', 'l2c', ',fiyz пшииукшыр', [ 'башня пшииукшыр' ] ];
		yield 'ru-both' => [ 'russian_keyboard', 'both', ',fiyz пшииукшыр', [ 'башня gibberish' ] ];
	}

	/**
	 * @dataProvider provideTestOneWayDwim
	 */
	public function testOneWayDwim( string $strategy, string $dir, string $input, array $expected ): void {
		$config[ 'dir' ] = $dir;
		$secondTryFactory = new SecondTrySearchFactory();
		$transformer = $secondTryFactory->build( $strategy, $config );
		$this->assertEquals( $expected, $transformer->candidates( $input ) );
	}

	public function provideTestFactory(): \Generator {
		yield 'he' => [ 'hebrew_keyboard', SecondTryHebrewKeyboard::class ];
		yield 'ru' => [ 'russian_keyboard', SecondTryRussianKeyboard::class ];
		yield 'geo_tr' => [ 'georgian_transliteration', SecondTryGeorgianTransliteration::class ];
		yield 'lang_converter' => [ 'language_converter', SecondTryLanguageConverter::class ];
		yield 'invalid' => [ 'foo', null ];
	}

	/**
	 * @dataProvider provideTestFactory
	 * @covers \CirrusSearch\SecondTry\SecondTrySearchFactory
	 */
	public function testFactory( string $strategy, ?string $expectedClass ): void {
		$languageConverter = $this->createMock( ILanguageConverter::class );
		$languageConverterFactory = $this->createMock( LanguageConverterFactory::class );
		$languageConverterFactory->method( 'getLanguageConverter' )->willReturn( $languageConverter );
		$factory = new SecondTrySearchFactory( $languageConverterFactory );
		if ( $expectedClass === null ) {
			$this->expectException( \InvalidArgumentException::class );
		}
		$this->assertInstanceOf( $expectedClass, $factory->build( $strategy, [] ) );
	}

	/**
	 * @covers \CirrusSearch\SecondTry\SecondTrySearchFactory
	 */
	public function testLangConvUninit(): void {
		$secondTryFactory = new SecondTrySearchFactory();
		$this->expectException( \InvalidArgumentException::class );
		$transformer = $secondTryFactory->build( 'language_converter', [] );
	}
}
