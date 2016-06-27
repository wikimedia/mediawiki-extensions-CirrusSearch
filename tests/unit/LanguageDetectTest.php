<?php

namespace CirrusSearch;

use CirrusSearch\LanguageDetector\TextCat;
use CirrusSearch\LanguageDetector\HttpAccept;

/**
 * Completion Suggester Tests
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
 */
class LanguageDetectTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @var \CirrusSearch
	 */
	private $cirrus;

	public function getLanguageTexts() {
		return [
			// simple cases
			["Welcome to Wikipedia, the free encyclopedia that anyone can edit", "en"],
			["Добро пожаловать в Википедию", "ru"],
			// more query-like cases
			["Breaking Bad", "en"],
			["Jesenwang flugplatz", "de"],
			["volviendose malo", "es"],
			["противоточный теплообменник", "ru"],
			["שובר שורות", "he"],
		];
	}

	public function setUp() {
		$this->cirrus = new \CirrusSearch();
		global $wgCirrusSearchTextcatModel;
		if (empty( $wgCirrusSearchTextcatModel ) ) {
			$tc = new \ReflectionClass('TextCat');
			$wgCirrusSearchTextcatModel = dirname($tc->getFileName())."/LM-query/";
		}
	}

	/**
	 * @dataProvider getLanguageTexts
	 * @param string $text
	 * @param string $language
	 */
	public function testTextCatDetector($text, $language) {
		// not really used for anything, but we need to pass it as a parameter
		$detector = new TextCat();
		$detect = $detector->detect($this->cirrus, $text);
		$this->assertEquals($language, $detect);
	}

	public function testTextCatDetectorLimited() {
		global $wgCirrusSearchTextcatLanguages;
		$wgCirrusSearchTextcatLanguages = ["en", "ru"];
		$detector = new TextCat();
		$detect = $detector->detect($this->cirrus, "volviendose malo");
		$this->assertEquals("en", $detect);
	}

	public function getHttpLangs() {
		return [
			["en", ["en"], null],
			["en", ["en-UK", "en-US"], null],
			["pt", ["pt-BR", "pt-PT"], null],
			["en", ["en-UK", "*"], null],
			["es", ["en-UK", "en-US"], "en"],
			["en", ["pt-BR", "en-US"], "pt"],
			["en", ["en-US", "pt-BR"], "pt"],
		];
	}

	/**
	 * @dataProvider getHttpLangs
	 * @param string $content
	 * @param array  $http
	 * @param string $result
	 */
	public function testHttpAcceptDetector($content, $http, $result) {
		$detector = new TestHttpAccept();

		$detector->setLanguages($content, $http);
		$detect = $detector->detect($this->cirrus, "test");
		$this->assertEquals($result, $detect);
	}
}

class TestHttpAccept extends HttpAccept {
	function setLanguages($content, $http) {
		$this->wikiLang = $content;
		$this->httpLang = $http;
	}
}
