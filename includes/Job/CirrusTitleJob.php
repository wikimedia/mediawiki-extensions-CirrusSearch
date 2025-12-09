<?php

namespace CirrusSearch\Job;

use CirrusSearch\SearchConfig;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

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
			$this->searchConfig = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}
		return $this->searchConfig;
	}
}
