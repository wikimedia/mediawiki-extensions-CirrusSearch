<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\Fetch\FetchPhaseConfigBuilder;
use Elastica\Query;
use Elastica\Response;
use MediaWiki\Title\Title;

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
 * @covers \CirrusSearch\Search\FullTextResultsType
 * @covers \CirrusSearch\Search\Fetch\FetchPhaseConfigBuilder
 * @covers \CirrusSearch\Search\Fetch\HighlightedField
 * @covers \CirrusSearch\Search\Fetch\BaseHighlightedField
 * @covers \CirrusSearch\Search\Fetch\ExperimentalHighlightedFieldBuilder
 * @group CirrusSearch
 * @todo Make this a unit test when moving away from Title(Factory)
 */
class ResultsTypeTest extends CirrusIntegrationTestCase {
	public static function fancyRedirectHandlingProvider() {
		return [
			'typical title only match' => [
				NS_MAIN,
				'Trebuchet',
				[
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
					],
				],
			],
			'partial title match' => [
				NS_MAIN,
				'Trebuchet',
				[
					'highlight' => [
						'title.prefix' => [
							'Trebuchet',
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
					],
				],
			],
			'full redirect match same namespace' => [
				NS_MAIN,
				'Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							'Pierriere',
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 0, 'title' => 'Pierriere' ]
						],
					],
				],
			],
			'full redirect match other namespace' => [
				NS_CATEGORY,
				'Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							'Pierriere',
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 14, 'title' => 'Pierriere' ]
						],
					],
				],
			],
			'partial redirect match other namespace' => [
				NS_CATEGORY,
				'Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							'Pierriere',
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 14, 'title' => 'Pierriere' ]
						],
					],
				],
			],
			'multiple redirect namespace matches' => [
				NS_USER,
				'Pierriere',
				[
					'highlight' => [
						'redirect.title.prefix' => [
							'Pierriere',
						],
					],
					'_source' => [
						'namespace_text' => '',
						'namespace' => 0,
						'title' => 'Trebuchet',
						'redirect' => [
							[ 'namespace' => 14, 'title' => 'Pierriere' ],
							[ 'namespace' => 2, 'title' => 'Pierriere' ],
						],
					],
				],
				[ 0, 2 ]
			],
		];
	}

	/**
	 * @covers \CirrusSearch\Search\FancyTitleResultsType
	 * @dataProvider fancyRedirectHandlingProvider
	 */
	public function testFancyRedirectHandling( $expectedNs, $expected, $hit, array $namespaces = [] ) {
		$type = new FancyTitleResultsType( 'prefix', self::newTitleHelper() );
		$result = new \Elastica\Result( $hit );
		$matches = $type->transformOneElasticResult( $result, $namespaces );
		$title = FancyTitleResultsType::chooseBestTitleOrRedirect( $matches );
		$this->assertEquals( Title::makeTitle( $expectedNs, $expected ), $title );
	}

	/**
	 * @covers \CirrusSearch\Search\FullTextResultsType
	 */
	public function testFullTextSyntax() {
		$res = new \Elastica\ResultSet( new Response( [] ), new Query( [] ), [] );
		$fullTextRes = new FullTextResultsType( new FetchPhaseConfigBuilder( new HashSearchConfig( [] ) ), true, self::newTitleHelper() );
		$this->assertTrue( $fullTextRes->transformElasticsearchResult( $res )->searchContainedSyntax() );

		$fullTextRes = new FullTextResultsType( new FetchPhaseConfigBuilder( new HashSearchConfig( [] ) ), false, self::newTitleHelper() );
		$this->assertFalse( $fullTextRes->transformElasticsearchResult( $res )->searchContainedSyntax() );
		$fullTextRes = new FullTextResultsType( new FetchPhaseConfigBuilder( new HashSearchConfig( [] ) ), false, self::newTitleHelper() );
		$this->assertFalse( $fullTextRes->transformElasticsearchResult( $res )->searchContainedSyntax() );
	}

	/**
	 * @covers \CirrusSearch\Search\FullTextResultsType::getSourceFiltering
	 */
	public function testExtraFields() {
		$fullTextRes = new FullTextResultsType( new FetchPhaseConfigBuilder( new HashSearchConfig( [] ) ),
			true, self::newTitleHelper(), [ 'extra_field1', 'extra_field2' ] );
		$this->assertContains( 'extra_field1', $fullTextRes->getSourceFiltering() );
		$this->assertContains( 'extra_field2', $fullTextRes->getSourceFiltering() );
	}

	public function testEmptyResultSet() {
		$fullTextRes = new FullTextResultsType( new FetchPhaseConfigBuilder( new HashSearchConfig( [] ) ), true, self::newTitleHelper() );
		$results = $fullTextRes->createEmptyResult();
		$this->assertSame( 0, $results->numRows() );
		$this->assertFalse( $results->hasMoreResults() );
	}

}
