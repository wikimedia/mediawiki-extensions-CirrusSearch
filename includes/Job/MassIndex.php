<?php

namespace CirrusSearch\Job;
use \CirrusSearch\Updater;
use \Title;
use \WikiPage;

/**
 * Job wrapper around Updater::updatePages.  Used by forceSearchIndex.php
 * when in job queueing mode.
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
class MassIndex extends Job {
	public static function build( $pages, $updateFlags ) {
		// Strip $pages down to PrefixedDBKeys so we don't put a ton of stuff in the job queue.
		$pageDBKeys = array();
		foreach ( $pages as $page ) {
			$pageDBKeys[] = $page->getTitle()->getPrefixedDBkey();
		}

		// We don't have a "title" for this job so we use the Main Page because it exists.
		return new self( Title::newMainPage(), array(
			'pageDBKeys' => $pageDBKeys,
			'updateFlags' => $updateFlags,
		) );
	}

	protected function doJob() {
		// Reload pages from pageIds to throw into the updater
		$pageData = array();
		foreach ( $this->params[ 'pageDBKeys' ] as $pageDBKey ) {
			$title = Title::newFromDBKey( $pageDBKey );
			// Skip any titles with broken keys.  We can't do anything with them.
			if ( !$title ) {
				wfLogWarning( "Skipping invalid DBKey:  $pageDBKey" );
				continue;
			}
			$pageData[] = WikiPage::factory( $title );
		}
		// Now invoke the updater!
		$updater = new Updater();
		$count = $updater->updatePages( $pageData, null, null, $this->params[ 'updateFlags' ] );
	}
}
