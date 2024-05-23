<?php

namespace CirrusSearch\Job;

use CirrusSearch\Updater;
use MediaWiki\Title\Title;

/**
 * Job to add pages to the archive index.
 */
class IndexArchive extends CirrusTitleJob {

	public function __construct( Title $title, array $params ) {
		parent::__construct( $title, $params );
	}

	public static function build( Title $title, string $docId, int $eventTime ): IndexArchive {
		return new self( $title, [
			'private_data' => true,
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
		if ( $this->getSearchConfig()->get( 'CirrusSearchIndexDeletes' ) ) {
			$updater->archivePages( [
				[
					'title' => $this->title,
					'page' => $this->params['docId'],
				],
			] );
		}

		return true;
	}

}
