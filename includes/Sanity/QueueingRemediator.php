<?php

namespace CirrusSearch\Sanity;
use \CirrusSearch\DeletePagesJob;
use \CirrusSearch\LinksUpdateJob;
use \JobQueueGroup;
use \WikiPage;

/**
 * Remediator implementation that queues jobs to fix the index.
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

class QueueingRemediator implements Remediator {
	public function redirectInIndex( $page ) {
		$this->pushLinksUpdateJob( $page );
	}
	public function pageNotInIndex( $page ) {
		$this->pushLinksUpdateJob( $page );
	}
	public function ghostPageInIndex( $pageId, $title ) {
		JobQueueGroup::singleton()->push(
			new DeletePagesJob( $title, array( 'id' => $pageId ) )
		);
	}
	public function pageInTooManyIndexes( $page, $fromIndex ) {
		// We need to run a delete then a reinsert to fix this so we do the delete in
		// process and queue the links update for the reinsert.
		$delete = new DeletePagesJob( $page->getTitle(), array( 'id' => $page->getId() ) );
		$delete->run();
		$this->pushLinksUpdateJob( $page );
	}

	private function pushLinksUpdateJob( $page ) {
		JobQueueGroup::singleton()->push(
			new LinksUpdateJob( $page->getTitle(), array(
				'addedLinks' => array(),
				'removedLinks' => array(),
			) )
		);
	}
}