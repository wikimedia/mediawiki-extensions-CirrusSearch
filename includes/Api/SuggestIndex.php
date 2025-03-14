<?php

namespace CirrusSearch\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\WikiMap\WikiMap;

/**
 * Update ElasticSearch suggestion index
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
class SuggestIndex extends ApiBase {
	use ApiTrait;

	public function execute() {
		// FIXME: This is horrible, no good, very bad hack. Only for testing,
		// and probably should be eventually replaced with something more sane.
		$updaterScript = "extensions/CirrusSearch/maintenance/UpdateSuggesterIndex.php";
		// detects between mediawiki-vagrant and mediawiki-docker-dev (mwcli/mwdd)
		$php = '/usr/local/bin/mwscript';
		if ( !file_exists( $php ) ) {
			$php = '/usr/bin/php';
		}
		$this->getResult()->addValue( null, 'result',
			wfShellExecWithStderr( "unset REQUEST_METHOD; $php $updaterScript --wiki " . WikiMap::getCurrentWikiId() )
		);
	}

	/**
	 * Mark as internal. This isn't meant to be used by normal api users
	 * @return bool
	 */
	public function isInternal() {
		return true;
	}
}
