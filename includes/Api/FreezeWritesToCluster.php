<?php

namespace CirrusSearch\Api;

use CirrusSearch\DataSender;

/**
 * Freeze/thaw writes to the ES cluster. This should *never* be made
 * available in a production environment and is used for browser tests.
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
class FreezeWritesToCluster extends ApiBase {
	public function execute() {
		$sender = new DataSender( $this->getCirrusConnection() );

		if ( $this->getParameter( 'thaw' ) ) {
			$sender->thawIndexes();
		} else {
			$sender->freezeIndexes();
		}
	}

	public function getAllowedParams() {
		return array(
			'thaw' => array()
		);
	}

	public function getParamDescription() {
		return array(
			'thaw' => 'Allow writes to the elasticsearch cluster. When not provided writes will be frozen.',
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return 'Freeze/thaw writes to the ES cluster. This should *never* be available in a production environment.';
	}
}
