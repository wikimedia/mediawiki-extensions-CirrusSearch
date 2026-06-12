<?php

namespace CirrusSearch\Job;

use CirrusSearch\SearchConfig;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;

/**
 * CirrusSearch Job that is bound to a Title
 *
 * @license GPL-2.0-or-later
 */
abstract class CirrusTitleJob extends Job {
	use JobTraits;

	// TODO: move these constants to JobTraits once we support php 8.2
	public const UPDATE_KIND = 'update_kind';
	public const ROOT_EVENT_TIME = 'root_event_time';
	/** a change made to the page, new revision/delete/restore */
	public const PAGE_CHANGE = 'page_change';
	/** a change that could possibly change the rendered output of the page */
	public const PAGE_REFRESH = 'page_refresh';
	/** a change emitted when detecting a visibility change on a past revision
	 * theoretically not needed but is being triggered out of caution as generally visibility changes
	 * occur to hide harmful content.
	 */
	public const VISIBILITY_CHANGE = "visibility_change";
	/**
	 * Change issued from the saneitizer, either a fixup or a forced update
	 */
	public const SANEITIZER = "saneitizer";

	/** param key to store the target elastic cluster */
	public const CLUSTER = 'cluster';

	/**
	 * @var SearchConfig|null (lazy loaded by getSearchConfig())
	 */
	private $searchConfig;

	/**
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		$params += [
			self::CLUSTER => null,
			self::UPDATE_KIND => 'unknown',
			self::ROOT_EVENT_TIME => null
		];
		// eg: DeletePages -> cirrusSearchDeletePages
		$jobName = self::buildJobName( static::class );

		parent::__construct( $jobName, $title, $params );

		// All CirrusSearch jobs are reasonably expensive.  Most involve parsing and it
		// is ok to remove duplicate _unclaimed_ cirrus jobs.  Once a cirrus job is claimed
		// it can't be deduplicated or else the search index will end up with out of date
		// data.  Luckily, this is how the JobQueue implementations work.
		$this->removeDuplicates = true;
	}

	public function getSearchConfig(): SearchConfig {
		if ( $this->searchConfig === null ) {
			// @phan-suppress-next-line PhanTypeMismatchProperty
			$this->searchConfig = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}
		return $this->searchConfig;
	}

	/**
	 * Assemble the UPDATE_KIND + ROOT_EVENT_TIME params shared by the page-update
	 * factory methods of the concrete jobs (e.g. LinksUpdate, UpdateRedirectDocument).
	 *
	 * ROOT_EVENT_TIME is seeded from the driving revision's timestamp when one is
	 * available, falling back to the current time (e.g. for deletes or for refreshes
	 * not tied to a specific revision).
	 *
	 * @param string $updateKind one of the *_CHANGE / *_REFRESH / SANEITIZER constants
	 * @param RevisionRecord|null $revisionRecord revision driving the update, if any
	 * @return array params to merge into the job's param array
	 */
	protected static function buildRootEventParams( string $updateKind, ?RevisionRecord $revisionRecord = null ): array {
		if ( $revisionRecord !== null && $revisionRecord->getTimestamp() !== null ) {
			$ts = (int)MWTimestamp::convert( TS_UNIX, $revisionRecord->getTimestamp() );
		} else {
			$ts = MWTimestamp::time();
		}
		return [
			self::UPDATE_KIND => $updateKind,
			self::ROOT_EVENT_TIME => $ts,
		];
	}
}
