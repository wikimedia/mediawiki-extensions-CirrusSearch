<?php

namespace CirrusSearch\Job;

use CirrusSearch\Updater;
use MediaWiki\Title\Title;

/**
 * Job wrapper around Updater::deletePages.  If indexSuffix parameter is
 * specified then only deletes from indices with a matching suffix.
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
class DeletePages extends CirrusTitleJob {
	public function __construct( Title $title, array $params ) {
		parent::__construct( $title, $params );

		// This is one of the cheapest jobs we have. Plus I'm reasonably
		// paranoid about deletions so I'd rather delete things extra times
		// if something actually requested it.
		$this->removeDuplicates = false;
	}

	public static function build( Title $title, string $docId, int $eventTime ): DeletePages {
		return new self( $title, [
			"docId" => $docId,
			self::UPDATE_KIND => self::PAGE_CHANGE,
			self::ROOT_EVENT_TIME => $eventTime
		] );
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		$updater = Updater::build( $this->getSearchConfig(), $this->params['cluster'] ?? null );
		// BC for rename from indexType to indexSuffix
		$indexSuffix = $this->params['indexSuffix'] ?? $this->params['indexType'] ?? null;
		$updater->deletePages( [ $this->title ], [ $this->params['docId'] ], $indexSuffix );

		return true;
	}
}
