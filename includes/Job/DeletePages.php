<?php

namespace CirrusSearch\Job;

use CirrusSearch\Updater;
use MediaWiki\Title\Title;

/**
 * Job wrapper around Updater::deletePages.  If indexSuffix parameter is
 * specified then only deletes from indices with a matching suffix.
 *
 * @license GPL-2.0-or-later
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
