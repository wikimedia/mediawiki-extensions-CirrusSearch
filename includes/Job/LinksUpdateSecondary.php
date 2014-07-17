<?php

namespace CirrusSearch\Job;
use \CirrusSearch\Updater;
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
class LinksUpdateSecondary extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( $title, $params );
	}

	protected function doJob() {
		// Load the titles and filter out any that no longer exist.
		$updater = new Updater();
		$updater->updateLinkedArticles( $this->params[ 'addedLinks' ],
			$this->params[ 'removedLinks' ] );

		// This job really doesn't matter if it fails, even if we could
		// verify one way or the other, which we can't. If it failed we
		// already logged further down--just release the job and move on
		return true;
	}
}
