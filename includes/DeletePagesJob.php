<?php

namespace CirrusSearch;

/**
 * Job wrapper around Updater::deletePages.  Used by CirrusSearch.php.
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
class DeletePagesJob extends Job {
	/**
	 * Build this job for just one title.
	 * @param $title Title title
	 * @param $id int article id of title
	 */
	public static function build( $title, $id ) {
		return new DeletePagesJob( $title, array( 'id' => $id ) );
	}

	protected function doJob() {
		global $wgCirrusSearchClientSideUpdateTimeout;

		Updater::deletePages( array( $this->title ),
			array( $this->params[ 'id' ] ), $wgCirrusSearchClientSideUpdateTimeout );
	}
}
