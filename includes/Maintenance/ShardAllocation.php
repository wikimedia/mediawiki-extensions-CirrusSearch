<?php

namespace CirrusSearch\Maintenance;

/**
 * Shard allocation maintenance.
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
class ShardAllocation {
	private $index;
	private $out;

	public function __construct( $index, $out ) {
		$this->index = $index;
		$this->out = $out;
	}

	public function validate() {
		global $wgCirrusSearchIndexAllocation;

		$this->out->outputIndented( "Validating shard allocation settings...\n" );

		$actual = $this->fetchActualAllocation();
		foreach( array( 'include', 'exclude', 'require' ) as $type ) {
			$desired = $wgCirrusSearchIndexAllocation[$type];
			if ( $desired ) {
				$this->out->outputIndented( "\tUpdating '$type' allocations..." );
				$this->set( $type, $desired );
				$this->out->output( "done\n" );
			}
			if( isset( $actual[$type] ) ) {
				$undesired = array_filter( array_keys( $actual[$type] ),
					function( $key ) use ( $actual, $type, $desired ) {
						return $actual[$type][$key] !== '' && !isset( $desired[$key] );
					}
				);

				if ( $undesired ) {
					$this->out->outputIndented( "\tClearing '$type' allocations..." );
					$this->set( $type, array_fill_keys( $undesired, '' ) );
					$this->out->output( "done\n" );
				}
			}
		}
	}

	private function fetchActualAllocation() {
		$settings = $this->index->getSettings()->get();
		return isset( $settings['routing']['allocation'] ) ?
			$settings['routing']['allocation'] : array();
	}

	private function set( $type, $allocation ) {
		$this->index->getSettings()->set( array(
			'routing' => array(
				'allocation' => array(
					$type => $allocation,
				)
			)
		) );
	}
}
