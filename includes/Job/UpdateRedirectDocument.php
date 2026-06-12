<?php

namespace CirrusSearch\Job;

use CirrusSearch\Updater;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;

/**
 * Indexes a redirect page's own first-class redirect document (page_type:redirect).
 *
 * Unlike LinksUpdate, which traces a redirect to its target and indexes the target, this job
 * indexes the redirect page directly via Updater::updateRedirectDocument(). The two run in
 * parallel for a single edit, preserving the "one job -> one document per page" invariant: the
 * LinksUpdate job refreshes the target's redirect[] array, this job writes the redirect's
 * document.
 *
 * Only meaningful when CirrusSearchRedirectDocuments['build'] is enabled; the callers
 * (ChangeListener, and later the saneitizer) are responsible for that gating.
 *
 * @license GPL-2.0-or-later
 */
class UpdateRedirectDocument extends CirrusTitleJob {

	/**
	 * Prepare a redirect-document update for a page that was directly changed
	 * (new revision/delete/restore).
	 *
	 * @param Title $title
	 * @param RevisionRecord|null $revisionRecord
	 * @param array $params
	 * @return self
	 */
	public static function newPageChangeUpdate( Title $title, ?RevisionRecord $revisionRecord, array $params ): self {
		$params += self::buildRootEventParams( self::PAGE_CHANGE, $revisionRecord );

		return new self( $title, $params );
	}

	/**
	 * Prepare a redirect-document update for when the rendered output of the page might have
	 * changed due to a change not directly related to this page (e.g. template update).
	 *
	 * @param Title $title
	 * @param array $params
	 * @return self
	 */
	public static function newPageRefreshUpdate( Title $title, array $params ): self {
		$params += self::buildRootEventParams( self::PAGE_REFRESH );

		return new self( $title, $params );
	}

	/**
	 * Prepare a redirect-document update issued by the saneitizer for the given cluster.
	 *
	 * @param Title $title
	 * @param string|null $cluster The cluster to update, or null for all clusters.
	 * @return self
	 */
	public static function newSaneitizerUpdate( Title $title, ?string $cluster ): self {
		$params = [
			self::CLUSTER => $cluster,
		] + self::buildRootEventParams( self::SANEITIZER );

		return new self( $title, $params );
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		$updater = Updater::build( $this->getSearchConfig(), $this->params[self::CLUSTER] ?? null );
		$updater->updateRedirectDocument(
			$this->title,
			$this->params[self::UPDATE_KIND],
			$this->params[self::ROOT_EVENT_TIME]
		);

		return true;
	}
}
