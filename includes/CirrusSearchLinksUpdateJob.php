<?php
/**
 * Performs the appropriate updates to Elasticsearch after a LinksUpdate is
 * completed.  The page itself is updated first then a second copy of this job
 * is queued to update linked articles.
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
class CirrusSearchLinksUpdateJob extends CirrusSearchJob {
	protected function doJob() {
		if ( $this->params[ 'primary' ] ) {
			CirrusSearchUpdater::updateFromTitle( $this->title );
			if ( count( $this->params[ 'addedLinks' ] ) > 0 ||
					count( $this->params[ 'removedLinks' ] ) > 0 ) {
				$next = new CirrusSearchLinksUpdateJob( $this->title, array(
					'addedLinks' => $this->params[ 'addedLinks' ],
					'removedLinks' => $this->params[ 'removedLinks' ],
					'primary' => false,
				) );
				JobQueueGroup::singleton()->push( $next );
			}
		} else {
			CirrusSearchUpdater::updateLinkedArticles( $this->params[ 'addedLinks' ],
				$this->params[ 'removedLinks' ] );
		}
	}
}
