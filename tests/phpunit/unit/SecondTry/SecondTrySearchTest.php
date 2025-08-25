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

}
