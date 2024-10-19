<?php

namespace CirrusSearch\Api;

use CirrusSearch\Connection;
use CirrusSearch\Sanity\BufferedRemediator;
use CirrusSearch\Sanity\Checker;
use CirrusSearch\Sanity\CheckerException;
use CirrusSearch\Sanity\Remediator;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use CirrusSearch\Util;
use MediaWiki\Api\ApiBase;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ParamValidator\ParamValidator;
use WikiMedia\ParamValidator\TypeDef\IntegerDef;

/**
 * Validates the sanity of the search indexes for a range of page id's
 *
 * Invokes the cirrus sanity checker which compares a range of page ids
 * current state in the sql database against the elasticsearch indexes.
 * Reports on issues found such as missing pages, pages that should have
 * been deleted, and old versions in the search index.
 *
 * Also offers a constant rerender-over-time through the sequenceid and
 * rerenderfrequency options. The sequenceid should be incremented each
 * time the same set of page ids is sent to the checker. A subset of
 * the page ids will be emit as `oldDocument` in each batch, such that
 * after `rerenderfrequency` increments of `sequenceid` all pages will
 * have been rerendered. The purpose of the over-time rerender is to
 * ensure changes to how pages are rendered make it into the search indexes
 * within an expected timeframe.
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
class CheckSanity extends ApiBase {
	use ApiTrait;

	public function execute() {
		$cluster = $this->getParameter( 'cluster' );
		// Start and end values are inclusive
		$start = $this->getParameter( 'from' );
		$end = $start + $this->getParameter( 'limit' ) - 1;

		$remediator = new BufferedRemediator();
		$this->check( $this->makeChecker( $cluster, $remediator ), $start, $end );
		$problems = $remediator->getActions();

		$result = $this->getResult();
		$result->addValue( null, 'wikiId', WikiMap::getCurrentWikiId() );
		$result->addValue(
			null, 'clusterGroup',
			$this->getSearchConfig()->getClusterAssignment()->getCrossClusterName() );
		$result->addValue( null, 'problems', $this->reformat( $problems ) );
	}

	protected function makeChecker( string $cluster, Remediator $remediator ): Checker {
		$searchConfig = $this->getSearchConfig();
		$connection = Connection::getPool( $searchConfig, $cluster );
		$searcher = new Searcher( $connection, 0, 0, $searchConfig, [], null );

		return new Checker(
			$searchConfig,
			$connection,
			$remediator,
			$searcher,
			Util::getStatsFactory(),
			false, // logSane
			false, // fastRedirectCheck
			null, // pageCache
			$this->makeIsOldClosure()
		);
	}

	private function makeIsOldClosure() {
		$sequenceId = $this->getParameter( 'sequenceid' );
		if ( $sequenceId === null ) {
			return null;
		}
		return Checker::makeIsOldClosure(
			$sequenceId,
			$this->getParameter( 'rerenderfrequency' )
		);
	}

	private function check( Checker $checker, int $start, int $end, int $batchSize = 10 ) {
		$ranges = array_chunk( range( $start, $end ), $batchSize );
		foreach ( $ranges as $pageIds ) {
			try {
				$checker->check( $pageIds );
			} catch ( CheckerException $e ) {
				// This mostly happens when there is a transient data loading problem.
				// The request should be retried.
				$this->dieWithException( $e );
			}
		}
	}

	/**
	 * Reformat Saneitizer problems for output
	 *
	 * Intentionally only emits numeric ids to avoid responding with
	 * any user generated data. As a list of page ids and index states
	 * this shouldn't be capable of leaking information thats not already
	 * known.
	 */
	private function reformat( array $problems ): array {
		$clean = [];
		$indexBaseName = $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME );
		// Generic connection for resolving index names, its always the same everywhere
		$connection = Connection::getPool( $this->getSearchConfig() );
		foreach ( $problems as [ $problem, $args ] ) {
			switch ( $problem ) {
				case 'redirectInIndex':
					[ $docId, $page, $indexSuffix ] = $args;
					$target = $page->getRedirectTarget();
					$targetIndexSuffix = $connection->getIndexSuffixForNamespace( $target->getNamespace() );
					$clean[] = [
						'indexName' => $connection->getIndexName( $indexBaseName, $indexSuffix ),
						'errorType' => $problem,
						'pageId' => $page->getId(),
						'namespaceId' => $page->getNamespace(),
						'target' => [
							'pageId' => $target->getId(),
							'namespaceId' => $target->getNamespace(),
							'indexName' => $connection->getIndexName( $indexBaseName, $targetIndexSuffix ),
						],
					];
					break;

				case 'pageNotInIndex':
				case 'oldDocument':
					[ $page ] = $args;
					$indexSuffix = $connection->getIndexSuffixForNamespace( $page->getNamespace() );
					$clean[] = [
						'indexName' => $connection->getIndexName( $indexBaseName, $indexSuffix ),
						'errorType' => $problem,
						'pageId' => $page->getId(),
						'namespaceId' => $page->getNamespace(),
					];
					break;

				case 'ghostPageInIndex':
					[ $docId, $title ] = $args;
					$indexSuffix = $connection->getIndexSuffixForNamespace( $title->getNamespace() );
					$clean[] = [
						'indexName' => $connection->getIndexName( $indexBaseName, $indexSuffix ),
						'errorType' => $problem,
						'pageId' => (int)$docId,
						'namespaceId' => $title->getNamespace(),
					];
					break;

				case 'pageInWrongIndex':
					[ $docId, $page, $wrongIndexSuffix ] = $args;
					$indexSuffix = $connection->getIndexSuffixForNamespace( $page->getNamespace() );
					$clean[] = [
						'wrongIndexName' => $connection->getIndexName( $indexBaseName, $wrongIndexSuffix ),
						'indexName' => $connection->getIndexName( $indexBaseName, $indexSuffix ),
						'errorType' => $problem,
						'pageId' => $page->getId(),
						'namespaceId' => $page->getNamespace(),
					];
					break;

				case 'oldVersionInIndex':
					// kinda random this one provides the suffix directly
					[ $docId, $page, $indexSuffix ] = $args;
					$clean[] = [
						'indexName' => $connection->getIndexName( $indexBaseName, $indexSuffix ),
						'errorType' => $problem,
						'pageId' => $page->getId(),
						'namespaceId' => $page->getNamespace(),
					];
					break;

				default:
					$this->dieDebug( __METHOD__, "Unknown remediation: $problem" );
			}
		}

		return $clean;
	}

	public function getAllowedParams() {
		$assignment = $this->getSearchConfig()->getClusterAssignment();
		return [
			'cluster' => [
				ParamValidator::PARAM_DEFAULT => $assignment->getSearchCluster(),
				ParamValidator::PARAM_TYPE => $assignment->getAllKnownClusters(),
			],
			'from' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 0,
			],
			'limit' => [
				ParamValidator::PARAM_DEFAULT => 100,
				ParamValidator::PARAM_TYPE => 'limit',
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_BIG1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			// The caller must increment the sequenceid each successive
			// time it invokes the sanity check for the same set of pages.
			// Pages within the batch will emit an `oldDocument` problem
			// spread over `rerenderfrequency` invocations of the api.
			// This supports a slow and constant rerender of all content,
			// ensuring the search indices stay aligned with changes to
			// indexing and rendering code.
			'sequenceid' => [
				// Providing this enables the "old document" checks
				// which provide constant re-rendering over time.
				ParamValidator::PARAM_TYPE => 'integer',
			],
			// Controls how often a page is flagged with the `oldDocument`
			// problem. If the caller scans all page ids every week, then
			// the default value of 16 would emit an `oldDocument` problem
			// for all existing pages spread over 16 weeks.
			'rerenderfrequency' => [
				ParamValidator::PARAM_DEFAULT => 16,
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 2,
			]
		];
	}

	/**
	 * Mark as internal. This isn't meant to be used by normal api users
	 * @return bool
	 */
	public function isInternal() {
		return true;
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=cirrus-sanity-check&from=0&limit=100' =>
				'apihelp-cirrus-check-sanity-example',
		];
	}

}
