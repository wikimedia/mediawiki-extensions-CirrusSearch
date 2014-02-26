<?php

namespace CirrusSearch;
use \Title;

/**
 * Performs the appropriate updates to Elasticsearch after a LinksUpdate is
 * completed.  The page itself is updated first then a second copy of this job
 * is queued to update linked articles if any links change.  The job can be
 * 'prioritized' via the 'prioritize' parameter which will switch it to a
 * different queue then the non-prioritized jobs.  Prioritized jobs will never
 * be deduplicated with non-prioritized jobs which is good because we can't
 * control which job is removed during deduplication.  In our case it'd only be
 * ok to remove the non-prioritized version.
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
class LinksUpdateSecondaryJob extends Job {
	public function __construct( $title, $params, $id = 0 ) {
		parent::__construct( $title, $params, $id );
	}

	protected function doJob() {
		// Load the titles and filter out any that no longer exist.
		$updater = new Updater();
		$updater->updateLinkedArticles(
			$this->loadTitles( $this->params[ 'addedLinks' ] ),
			$this->loadTitles( $this->params[ 'removedLinks' ] ) );
	}

	/**
	 * Convert a serialized title to a title ready to be passed to updateLinkedArticles.
	 * @param Title|string $title Either a Title or a string to be loaded.
	 * @return array(Title) loaded titles
	 */
	private function loadTitles( $titles ) {
		$result = array();
		foreach ( $titles as $title ) {
			// TODO remove support for Title objects when the queues have drained of them
			if ( $title instanceof Title ) {
				$result[] = $title;
			} else {
				$title = Title::newFromDBKey( $title );
				if ( $title ) {
					$result[] = $title;
				}
			}
		}
		return $result;
	}
}
