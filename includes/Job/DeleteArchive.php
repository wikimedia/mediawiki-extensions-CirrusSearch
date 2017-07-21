<?php

namespace CirrusSearch\Job;

use CirrusSearch\Connection;

/**
 * Job wrapper for deleting pages from archive.
 */
class DeleteArchive extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( $title, $params );

		// Don't remove dupes since we do checks that may return different results
		// Also, deletes are idempotent so it's no problem if we delete twice.
		$this->removeDuplicates = false;
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		$archive = new \PageArchive( $this->title );
		$docs = $this->params['docIds'];

		// Remove page IDs that still have archived revs
		foreach ( $archive->listRevisions() as $rev ) {
			unset( $docs[$rev->ar_page_id] );
		}

		if ( empty( $docs ) ) {
			// If we have more deleted instances of the same title, no need to bother.
			return true;
		}

		$updater = $this->createUpdater();
		$updater->deletePages(
			[ $this->title ],
			array_keys( $docs ),
			Connection::GENERAL_INDEX_TYPE,
			Connection::ARCHIVE_TYPE_NAME
		);

		return true;
	}
}
