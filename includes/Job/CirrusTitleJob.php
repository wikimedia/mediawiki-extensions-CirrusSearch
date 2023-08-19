<?php

namespace CirrusSearch\Job;

use CirrusSearch\SearchConfig;
use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * CirrusSearch Job that is bound to a Title
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

	/**
	 * @return SearchConfig
	 */
	public function getSearchConfig(): SearchConfig {
		if ( $this->searchConfig === null ) {
			$this->searchConfig = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}
		return $this->searchConfig;
	}
}
