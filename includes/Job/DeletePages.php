<?php

namespace CirrusSearch\Job;

use MediaWiki\MediaWikiServices;

/**
 * Job wrapper around Updater::deletePages.  If indexType parameter is
 * specified then only deletes from that type of index.
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
class DeletePages extends Job {
	public function __construct( $title, $params ) {
		// BC for jobs created before this explicitly handled document id's
		if ( isset( $params['id'] ) ) {
			// parent::__construct would set $this->searchConfig, but we need this early. Since
			// it's only for BC probably ok to leave this dirtiness for now.
			$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
			/** @suppress PhanUndeclaredMethod phan doesn't know this is a SearchConfig */
			$params['docId'] = $config->makeId( $params['id'] );
			unset( $params['id'] );
		}
		parent::__construct( $title, $params );

		// This is one of the cheapest jobs we have. Plus I'm reasonably
		// paranoid about deletions so I'd rather delete things extra times
		// if something actually requested it.
		$this->removeDuplicates = false;
	}

	protected function doJob() {
		global $wgCirrusSearchClientSideUpdateTimeout;

		$updater = $this->createUpdater();
		$indexType = isset( $this->params[ 'indexType' ] ) ? $this->params[ 'indexType' ] : null;
		return $updater->deletePages( array( $this->title ), array( $this->params[ 'docId' ] ),
			$wgCirrusSearchClientSideUpdateTimeout, $indexType );
	}
}
