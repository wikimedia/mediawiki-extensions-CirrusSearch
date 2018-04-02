<?php

namespace CirrusSearch;

use CirrusSearch\Search\CirrusIndexField;

/**
 * Test Updater methods
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
 * @group CirrusSearch
 */
class DataSenderTest extends CirrusTestCase {
	/**
	 * @dataProvider provideDocs
	 */
	public function testSuperNoopExtraHandlers( array $rawDoc, array $hints, array $extraHandlers, array $expectedParams ) {
		$config = $this->buildConfig( $extraHandlers );
		$conn = new Connection( $config );
		$updater = new DataSender( $conn, $config );
		$doc = $this->builDoc( $rawDoc, $hints );
		$script = $updater->docToSuperDetectNoopScript( $doc, false );
		$this->assertEquals( 'super_detect_noop', $script->getLang() );
		$this->assertEquals( $expectedParams['handlers'], $script->getParams()['handlers'] );
		$this->assertEquals( $expectedParams['_source'], $script->getParams()['source'] );
		$script = $updater->docToSuperDetectNoopScript( $doc, true );
		$this->assertEquals( 'native', $script->getLang() );
		$this->assertEquals( $expectedParams['handlers'], $script->getParams()['handlers'] );
		$this->assertEquals( $expectedParams['_source'], $script->getParams()['source'] );
	}

	public static function provideDocs() {
		return [
			'simple' => [
				[
					123 => [ 'title' => 'test' ]
				],
				[
					'incoming_links' => 'within 20%',
				],
				[
					'labels' => 'equals',
					'version' => 'documentVersion',
				],
				[
					'handlers' => [
						'incoming_links' => 'within 20%',
						'labels' => 'equals',
						'version' => 'documentVersion',
					],
					'_source' => [
						'title' => 'test',
					],
				],
			],
			'do not override' => [
				[
					123 => [ 'title' => 'test' ]
				],
				[
					'incoming_links' => 'within 20%',
				],
				[
					'labels' => 'equals',
					'version' => 'documentVersion',
					'incoming_links' => 'within 30%',
				],
				[
					'handlers' => [
						'incoming_links' => 'within 20%',
						'labels' => 'equals',
						'version' => 'documentVersion',
					],
					'_source' => [
						'title' => 'test',
					],
				],
			],
			'no hints' => [
				[
					123 => [ 'title' => 'test' ]
				],
				[],
				[
					'labels' => 'equals',
					'version' => 'documentVersion',
					'incoming_links' => 'within 30%',
				],
				[
					'handlers' => [
						'incoming_links' => 'within 30%',
						'labels' => 'equals',
						'version' => 'documentVersion',
					],
					'_source' => [
						'title' => 'test',
					],
				],
			],
		];
	}

	private function buildConfig( array $extraHandlers ) {
		return new HashSearchConfig( [
			'CirrusSearchWikimediaExtraPlugin' => [
				'super_detect_noop' => true,
				'super_detect_noop_handlers' => $extraHandlers,
			],
		], [ 'inherit' ] );
	}

	private function builDoc( array $doc, array $hints ) {
		$doc = new \Elastica\Document( key( $doc ), reset( $doc ) );
		foreach ( $hints as $f => $h ) {
			CirrusIndexField::addNoopHandler( $doc, $f, $h );
		}
		return $doc;
	}
}
