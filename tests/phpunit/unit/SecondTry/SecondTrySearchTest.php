<?php

namespace CirrusSearch\SecondTry;

use CirrusSearch\CirrusTestCase;

/**
 * Test escaping search strings.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @covers \CirrusSearch\SecondTry\SecondTrySearch
 * @group CirrusSearch
 */
class SecondTrySearchTest extends CirrusTestCase {

	/** @var SecondTrySearch */
	private $transformer;

	/**
	 * @dataProvider provideRuQwertyWrongKeyboard
	 */
	public function testRussianWrongKeyboard( string $input, string $expected ) {
		$this->transformer = new SecondTrySearch();
		$actual = $this->transformer->russianWrongKeyboard( $input );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideRuQwertyWrongKeyboard() {
		return [
			'ru to qwerty' => [ 'тще огые пшииукшыр', 'not just gibberish' ],
			'qwerty to ru lower' => [ 'qatktdf ,fiyz', 'йфелева башня' ],
			'qwerty to ru upper' => [ 'QATKTDF <FIYZ', 'ЙФЕЛЕВА БАШНЯ' ],
			'all punctuation to ru' => [ ':\'[\' :\',\'[\' <\'.', 'Жэхэ Жэбэхэ Бэю' ],
			'mixed scripts' => [ 'qatktdf ,fiyz тще огые пшииукшыр',
				'йфелева башня not just gibberish' ],
			'nothing relevant' => [ 'ウィキペディア ᐅᐃᑭᐱᑎᐊ', 'ウィキペディア ᐅᐃᑭᐱᑎᐊ' ], // Japanese, Inuktitut
		];
	}

	/**
	 * @dataProvider provideHeQwertyWrongKeyboard
	 */
	public function testHebrewWrongKeyboard( string $input, string $expected ) {
		$this->transformer = new SecondTrySearch();
		$actual = $this->transformer->hebrewWrongKeyboard( $input );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideHeQwertyWrongKeyboard() {
		return [
			'he to qwerty' => [ 'מםא חודא עןננקרןדי', 'not just gibberish' ],
			'qwerty to he lower' => [ 'ndsk thhpk', 'מגדל אייפל' ],
			'qwerty to he upper' => [ 'NDSK THHPK', 'מגדל אייפל' ],
			'mixed scripts' => [ 'מםא חודא עןננקרןדי NDSK thhpk',
				'not just gibberish מגדל אייפל' ],
			'mixed case' => [ 'MבMשמדןםמ', 'McMansion' ],
			'nothing relevant' => [ 'ウィキペディア ᐅᐃᑭᐱᑎᐊ', 'ウィキペディア ᐅᐃᑭᐱᑎᐊ' ], // Japanese, Inuktitut
		];
	}

	/**
	 * @dataProvider provideGeorgianTransliteration
	 */
	public function testGeorgianTransliteration( string $input, string $expected ) {
		$this->transformer = new SecondTrySearch();
		$actual = $this->transformer->georgianTransliteration( $input );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideGeorgianTransliteration() {
		return [
			'mixed-case latin to geo' => [ 'saqarTvelos istoria', 'საქართველოს ისტორია' ],
			'latin with digraphs' => [ 'chubinashvili', 'ჩუბინაშვილი' ],
			'first letter capitalized latin' => [ 'beroshvili Beroshvili', 'ბეროშვილი ბეროშვილი' ],
			'unmappable latin 1' => [ 'été', 'été' ],
			'unmappable latin 2' => [ 'ʃəzæm', 'ʃəzæm' ],
			'overlapping latin diagraphs 1' => [ 'hatsha HATSHA HaTsha', 'ჰაცჰა ჰაცჰა ჰაცჰა' ], // ts + h
			'overlapping latin diagraphs 2' => [ 'hatSha', 'ჰატშა' ], // t + sh
			'simple cyrillic' => [ 'кавазашвили', 'კავაზაშვილი' ],
			'cyrillic with diacritics' => [ 'Кавазашви́ли', 'კავაზაშვილი' ],
			'cyrillic digraphs' => [ 'Ягор Абашидзе', 'იაგორ აბაშიძე' ],
			'unmappable cyrillic 1' => [ 'щёлк', 'щёлк' ],
			'unmappable cyrillic 2' => [ 'Аҧсуа', 'Аҧсуа' ],
			'contextual cyrillic' => [ 'трото тлатла ппп ттт', 'ტროტო თლათლა ფფპ თტთ' ], // nonsense syllables
			'mixed latin + cyrillic' => [ 'Дaviд', 'დავიდ' ], // why? people are weird
			'ignore georgian' => [ 'ვიკიპედია', 'ვიკიპედია' ],
			'ignore mixed georgian' => [ 'ვიკიპედია Wikipedia Википедия', 'ვიკიპედია Wikipedia Википедия' ],
			'nothing relevant' => [ 'ウィキペディア ᐅᐃᑭᐱᑎᐊ', 'ウィキペディア ᐅᐃᑭᐱᑎᐊ' ], // Japanese, Inuktitut
		];
	}

}
