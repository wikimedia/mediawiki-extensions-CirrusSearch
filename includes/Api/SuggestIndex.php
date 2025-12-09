<?php

namespace CirrusSearch\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\WikiMap\WikiMap;

/**
 * Update ElasticSearch suggestion index
 *
 * @license GPL-2.0-or-later
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
