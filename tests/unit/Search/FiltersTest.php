<?php

namespace CirrusSearch\Search;

use \Elastica\Filter\Bool;
use \Elastica\Filter\BoolAnd;
use \Elastica\Filter\BoolNot;
use \Elastica\Filter\Script;
use \Elastica\Filter\Term;
use \MediaWikiTestCase;

/**
 * Test for filter utilities.
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
class FiltersTest extends MediaWikiTestCase {
	/**
	 * @dataProvider unifyTestCases
	 */
	public function testUnify( $expected, $mustFilters, $mustNotFilters ) {
		if ( !is_array( $mustFilters ) ) {
			$mustFilters = array( $mustFilters );
		}
		if ( !is_array( $mustNotFilters ) ) {
			$mustNotFilters = array( $mustNotFilters );
		}
		$this->assertEquals( $expected, Filters::unify( $mustFilters, $mustNotFilters ) );
	}

	public static function unifyTestCases() {
		$scriptOne = new Script( 'dummy1' );
		$scriptTwo = new Script( 'dummy2' );
		$scriptThree = new Script( 'dummy3' );
		$foo = new Term( array( 'test' => 'foo' ) );
		$bar = new Term( array( 'test' => 'bar' ) );
		$baz = new Term( array( 'test' => 'baz' ) );
		return array(
			array( null, array(), array() ),
			array( $scriptOne, $scriptOne, array() ),
			array( new BoolNot( $scriptOne ), array(), $scriptOne ),
			array( $foo, $foo, array() ),
			array( new BoolNot( $foo ), array(), $foo ),
			array(
				self::newBool( array( $foo, $bar ), array() ),
				array( $foo, $bar ),
				array()
			),
			array(
				self::newBool( array(), array( $foo, $bar ) ),
				array(),
				array( $foo, $bar ),
			),
			array(
				self::newBool( array( $baz ), array( $foo, $bar ) ),
				array( $baz ),
				array( $foo, $bar ),
			),
			array(
				self::newAnd(
					self::newBool( array( $baz ), array( $foo, $bar ) ),
					$scriptOne
				),
				array( $scriptOne, $baz ),
				array( $foo, $bar ),
			),
			array(
				self::newAnd(
					self::newBool( array( $baz ), array( $foo, $bar ) ),
					$scriptOne,
					$scriptTwo,
					new BoolNot( $scriptThree )
				),
				array( $scriptOne, $baz, $scriptTwo ),
				array( $foo, $scriptThree, $bar ),
			),
		);
	}

	/**
	 * Convenient helper for building Bool filters.
	 * @param AbstractFilter|array(AbstractFilter) $must must filters
	 * @param AbstractFilter|array(AbstractFilter) $mustNot must not filters
	 * @return Bool a bool filter containing $must and $mustNot
	 */
	private static function newBool( $must, $mustNot ) {
		$bool = new Bool();
		if ( is_array( $must ) ) {
			foreach ( $must as $m ) {
				$bool->addMust( $m );
			}
		} else {
			$bool->addMust( $must );
		}
		if ( is_array( $mustNot ) ) {
			foreach ( $mustNot as $m ) {
				$bool->addMustNot( $m );
			}
		} else {
			$bool->addMustNot( $mustNot );
		}

		return $bool;
	}

	private static function newAnd( /* args */ ) {
		$and = new BoolAnd();
		$and->setFilters( func_get_args() );
		return $and;
	}
}
