<?php
/**
 * Job wrapper around CirrusSearchUpdater::updatePages.  Used by
 * forceSearchIndex.php when in job queueing mode.
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
class CirrusSearchUpdatePagesJob extends Job {
	public static function build( $revisions, $checkFreshness ) {
		// We don't have a "title" for this job so we use the Main Page because it exists.
		$title = Title::newFromText( 'Main Page' );

		// Strip $revisions down to page ids so we don't put a ton of stuff in the job queue.
		$pageIds = array();
		foreach ( $revisions as $rev ) {
			$pageIds[] = $rev[ 'id' ];
		}
		return new CirrusSearchUpdatePagesJob( $title, array(
			'pageIds' => $pageIds,
			'checkFreshness' => $checkFreshness,
		) );
	}

	public function __construct( $title, $params, $id = 0 ) {
		parent::__construct( 'cirrusSearchUpdatePages', $title, $params, $id );
	}

	public function run() {
		// Reload pages from pageIds to throw into the updater
		$pageData = array();
		foreach ( $this->params[ 'pageIds' ] as $pageId ) {
			$pageData[] = array( 'page' => WikiPage::newFromID( $pageId ) );
		}
		// Now invoke the updater!
		CirrusSearchUpdater::updatePages( $pageData, $this->params[ 'checkFreshness' ] );
	}
}
