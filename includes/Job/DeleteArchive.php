<?php

namespace CirrusSearch\Job;

use CirrusSearch\Connection;
use CirrusSearch\Updater;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Job wrapper for deleting pages from archive.
 */
class DeleteArchive extends CirrusTitleJob {
	public function __construct( Title $title, array $params ) {
		// While the delete is not itself private, it can only fail on clusters
		// without private data as the index does not exist.
		parent::__construct( $title, [ 'private_data' => true ] + $params );

		// Don't remove dupes since we do checks that may return different results
		// Also, deletes are idempotent so it's no problem if we delete twice.
		$this->removeDuplicates = false;
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		$docs = $this->params['docIds'];

		// Remove page IDs that still have archived revs
		$archivedRevisionLookup = MediaWikiServices::getInstance()->getArchivedRevisionLookup();
		foreach ( $archivedRevisionLookup->listRevisions( $this->title ) as $rev ) {
			unset( $docs[$rev->ar_page_id] );
		}

		if ( !$docs ) {
			// If we have more deleted instances of the same title, no need to bother.
			return true;
		}

		$updater = Updater::build( $this->getSearchConfig(), $this->params['cluster'] ?? null );
		$updater->deletePages(
			[ $this->title ],
			array_keys( $docs ),
			Connection::ARCHIVE_INDEX_SUFFIX,
			[ 'private_data' => true ]
		);

		return true;
	}
}
