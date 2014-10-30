<?php

namespace CirrusSearch\Maintenance;

/**
 * Splits maintenance scripts into chunks and prints out the commands to run
 * the chunks.
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
class ChunkBuilder {
	public function build( $self, $options, $buildChunks, $from, $to ) {
		$fixedChunkSize = strpos( $buildChunks, 'total' ) === false;
		$buildChunks = intval( $buildChunks );
		if ( $fixedChunkSize ) {
			$chunkSize = $buildChunks;
		} else {
			$chunkSize = max( 1, ceil( ( $to - $from ) / $buildChunks ) );
		}
		for ( $id = $from; $id < $to; $id = $id + $chunkSize ) {
			$chunkToId = min( $to, $id + $chunkSize );
			print "php $self";
			foreach ( $options as $optName => $optVal ) {
				if ( $optVal === null || $optVal === false || $optName === 'fromId' ||
						$optName === 'toId' || $optName === 'buildChunks' ||
						($optName === 'memory-limit' && $optVal === 'max')) {
					continue;
				}
				print " --$optName $optVal";
			}
			print " --fromId $id --toId $chunkToId\n";
		}
	}
}
