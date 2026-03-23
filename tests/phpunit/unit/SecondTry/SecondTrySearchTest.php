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
 * @covers \CirrusSearch\SecondTry\SecondTryHindiTransliteration
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
		// namespace handling: colon passes through strtr unchanged
		yield 'he namespace qwerty to he' => [ 'hebrew_keyboard', 'uhehpshv:cn', [ 'ויקיפדיה:במ' ] ];
		yield 'he namespace he to qwerty' => [ 'hebrew_keyboard', "'ןלןפקגןש:מפםה", [ 'wikipedia:npov' ] ];
		yield 'he namespace mixed case' => [ 'hebrew_keyboard', 'Wןלןפקגןש:NPOV', [ 'Wikipedia:NPOV' ] ];
		yield 'he namespace with body words' => [ 'hebrew_keyboard', 'uhehpshv:cn cv', [ 'ויקיפדיה:במ בה' ] ];

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

		// hindi_transliteration
			// no mapping accidentally gets this one, so test hard-coding
			// Mix up the cases and make sure word splitting is working
		yield 'hin_tr hard-coded word 1' => [ 'hindi_transliteration', 'om', [ 'ॐ' ] ];
		yield 'hin_tr hard-coded word 2' => [ 'hindi_transliteration', 'OM oM Om', [ 'ॐ ॐ ॐ' ] ];

			// mixed script / mixed case variations
		yield 'hin_tr mixed script 1' => [ 'hindi_transliteration', 'hInDi हिंदी', [ 'हिंदी हिंदी' ] ];
		yield 'hin_tr mixed script 2' => [ 'hindi_transliteration', 'हिंदी HindI', [ 'हिंदी हिंदी' ] ];
		yield 'hin_tr mixed script 3' => [ 'hindi_transliteration', 'SHooNYa एक Do तीन cHAR पांच',
			[ 'शून्य एक दो तीन चार पांच' ] ]; // multi-chunk, Latin chunk first, Hindi chunk last
		yield 'hin_tr mixed script 4' => [ 'hindi_transliteration', 'एक dO तीन cHaR पांच shOOnyA',
			[ 'एक दो तीन चार पांच शून्य' ] ]; // multi-chunk, Hindi chunk first, Latin chunk last

			// admittedly weird, but test parsing chunks
		yield 'hin_tr preserve symbols and spacing' => [ 'hindi_transliteration',
			'hiNdi   hIndI-हिंदीhinDiहिंदी÷hindi.0*(हिंदी', [ 'हिंदी   हिंदी-हिंदीहिंदीहिंदी÷हिंदी.0*(हिंदी' ] ];

			// non-hard-coded words we get right, touching various specific rules
		yield 'hin_tr rules 1' => [ 'hindi_transliteration', 'aavAAseeya', [ 'आवासीय' ] ]; # eeya
		yield 'hin_tr rules 2' => [ 'hindi_transliteration', 'aksHAr', [ 'अक्षर' ] ]; # sh
		yield 'hin_tr rules 3' => [ 'hindi_transliteration', 'bhaNWar', [ 'भंवर' ] ]; # bh,w
		yield 'hin_tr rules 4' => [ 'hindi_transliteration', 'coaCHuveli', [ 'कोचुवेली' ] ]; # oa,ch
		yield 'hin_tr rules 5' => [ 'hindi_transliteration', 'husSAin', [ 'हुसैन' ] ]; # ss
		yield 'hin_tr rules 6' => [ 'hindi_transliteration', 'jivAK', [ 'जीवक' ] ]; # jiv
		yield 'hin_tr rules 7' => [ 'hindi_transliteration', 'jujHAar', [ 'जुझार' ] ]; # jh
		yield 'hin_tr rules 8' => [ 'hindi_transliteration', 'kicHHu', [ 'किछु' ] ]; # chh
		yield 'hin_tr rules 9' => [ 'hindi_transliteration', 'knoCK', [ 'नोक' ] ]; # kn,ck
		yield 'hin_tr rules 10' => [ 'hindi_transliteration', 'laCChe', [ 'लच्छे' ] ]; # cch
		yield 'hin_tr rules 11' => [ 'hindi_transliteration', 'meNTion', [ 'मेंशन' ] ]; # n,tion
		yield 'hin_tr rules 12' => [ 'hindi_transliteration', 'miSSion', [ 'मिशन' ] ]; # sion
		yield 'hin_tr rules 13' => [ 'hindi_transliteration', 'miXTure', [ 'मिक्सचर' ] ]; # x,ture
		yield 'hin_tr rules 14' => [ 'hindi_transliteration', 'ouTReach', [ 'आउटरीच' ] ]; # out,ch
		yield 'hin_tr rules 15' => [ 'hindi_transliteration', 'paAMir', [ 'पामीर' ] ]; # ir
		yield 'hin_tr rules 16' => [ 'hindi_transliteration', 'paSChaat', [ 'पश्चात' ] ]; # sch
		yield 'hin_tr rules 17' => [ 'hindi_transliteration', 'paTRikaaaen', [ 'पत्रिकाएं' ] ]; # aaae
		yield 'hin_tr rules 18' => [ 'hindi_transliteration', 'riDGe', [ 'रिज' ] ]; # dge
		yield 'hin_tr rules 19' => [ 'hindi_transliteration', 'roDHi', [ 'रोधी' ] ]; # dh
		yield 'hin_tr rules 20' => [ 'hindi_transliteration', 'siNGham', [ 'सिंघम' ] ]; # ngh

		yield 'hin_tr all devanagari' => [ 'hindi_transliteration', 'हिंदी', [] ];
		yield 'hin_tr unmappable latin 1' => [ 'hindi_transliteration', 'été', [] ];
		yield 'hin_tr unmappable latin 2' => [ 'hindi_transliteration', 'ʃəzæm', [] ];
		yield 'hin_tr nothing relevant' => [ 'hindi_transliteration', 'ウィキペディア ᐅᐃᑭᐱᑎᐊ', [] ]; // Japanese, Inuktitut

		yield 'icu naive' => [ 'icu_folding', 'Foô', [ 'foo' ] ];
		yield 'icu naive unchanged' => [ 'icu_folding', 'foo', [], [ 'method' => 'naive' ] ];
		yield 'icu naive utr30' => [ 'icu_folding', 'Æpyornis', [ 'aepyornis' ], [ 'method' => 'utr30' ] ];
	}

	/**
	 * @dataProvider provideTestSecondTry
	 */
	public function testSecondTry( string $strategy, string $input, array $expected, array $config = [] ): void {
		$secondTryFactory = new SecondTrySearchFactory( $this->createMock( LanguageConverterFactory::class ) );
		$transformer = $secondTryFactory->build( $strategy, $config );
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

	public static function provideTestFactory(): \Generator {
		yield 'he_kbd' => [ 'hebrew_keyboard', SecondTryHebrewKeyboard::class, [], [ 'dir' => 'h2l' ] ];
		yield 'he_kbd-h2l' => [ 'hebrew_keyboard', SecondTryHebrewKeyboard::class, [ 'dir' => 'h2l' ] ];
		yield 'he_kbd-h2q' => [ 'hebrew_keyboard', SecondTryHebrewKeyboard::class, [ 'dir' => 'h2q' ] ];
		yield 'he_kbd-q2h' => [ 'hebrew_keyboard', SecondTryHebrewKeyboard::class, [ 'dir' => 'q2h' ] ];
		yield 'he_kbd-l2h' => [ 'hebrew_keyboard', SecondTryHebrewKeyboard::class, [ 'dir' => 'l2h' ] ];
		yield 'he_kbd-both' => [ 'hebrew_keyboard', SecondTryHebrewKeyboard::class, [ 'dir' => 'both' ] ];
		yield 'ru_kbd' => [ 'russian_keyboard', SecondTryRussianKeyboard::class, [], [ 'dir' => 'r2q' ] ];
		yield 'ru_kbd-r2q' => [ 'russian_keyboard', SecondTryRussianKeyboard::class, [ 'dir' => 'r2q' ] ];
		yield 'ru_kbd-c2l' => [ 'russian_keyboard', SecondTryRussianKeyboard::class, [ 'dir' => 'c2l' ] ];
		yield 'ru_kbd-q2r' => [ 'russian_keyboard', SecondTryRussianKeyboard::class, [ 'dir' => 'q2r' ] ];
		yield 'ru_kbd-l2c' => [ 'russian_keyboard', SecondTryRussianKeyboard::class, [ 'dir' => 'l2c' ] ];
		yield 'ru_kbd-both' => [ 'russian_keyboard', SecondTryRussianKeyboard::class, [ 'dir' => 'both' ] ];
		yield 'geo_tr' => [ 'georgian_transliteration', SecondTryGeorgianTransliteration::class, [] ];
		yield 'hin_tr' => [ 'hindi_transliteration', SecondTryHindiTransliteration::class, [] ];
		yield 'lang_converter' => [ 'language_converter', SecondTryLanguageConverter::class, [] ];
		yield 'icu_folding' => [ 'icu_folding', SecondTryICUFolding::class, [], [ 'method' => 'utr30' ] ];
		yield 'icu_folding-naive' => [ 'icu_folding', SecondTryICUFolding::class, [ 'method' => 'naive' ] ];
		yield 'icu_folding-utr30' => [ 'icu_folding', SecondTryICUFolding::class, [ 'method' => 'utr30' ] ];
		yield 'invalid' => [ 'foo', null, [] ];
	}

	/**
	 * @dataProvider provideTestFactory
	 * @covers \CirrusSearch\SecondTry\SecondTrySearchFactory
	 */
	public function testFactory( string $strategy, ?string $expectedClass, ?array $config, ?array $anotherConfig = null ): void {
		$languageConverter = $this->createMock( ILanguageConverter::class );
		$languageConverterFactory = $this->createMock( LanguageConverterFactory::class );
		$languageConverterFactory->method( 'getLanguageConverter' )->willReturn( $languageConverter );
		$factory = new SecondTrySearchFactory( $languageConverterFactory );
		if ( $expectedClass === null ) {
			$this->expectException( \InvalidArgumentException::class );
		}
		$actual = $factory->build( $strategy, $config );
		$this->assertInstanceOf( $expectedClass, $factory->build( $strategy, $config ) );
		$this->assertSame( $actual, $factory->build( $strategy, $config ) );
		if ( $anotherConfig !== null ) {
			// when provided another config make sure we don't pollute the cache with unrelated
			// strategies
			$anotherStrategy = $factory->build( $strategy, $anotherConfig );
			$this->assertInstanceOf( $expectedClass, $anotherStrategy );
			$this->assertNotSame( $actual, $anotherStrategy );
		}
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
