<?php

namespace CirrusSearch\Job;

use \JobQueueGroup;
use \Title;

/**
 * Tombstone job to convert currently queued jobs into the new
 * IncomingLinkCount job type.
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
class LinksUpdateSecondary extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( $title, $params );
		// TODO Remove this job when it has drained from the queues
	}

	protected function doJob() {
		$titleKeys = array_merge( $this->params[ 'addedLinks' ],
			$this->params[ 'removedLinks' ] );
		foreach ( $titleKeys as $titleKey ) {
			$title = Title::newFromDBKey( $titleKey );
			if ( !$title ) {
				continue;
			}
			$linkCount = new IncomingLinkCount( $title, array() );
			JobQueueGroup::singleton()->push( $linkCount );
		}

		return true;
	}
}
