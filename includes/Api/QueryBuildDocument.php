<?php

namespace CirrusSearch\Api;

use CirrusSearch\BuildDocument\BuildDocument;
use CirrusSearch\BuildDocument\DocumentSizeLimiter;
use CirrusSearch\CirrusSearch;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\CirrusIndexField;
use CirrusSearch\SearchConfig;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Generate CirrusSearch document for page.
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
class QueryBuildDocument extends ApiQueryBase {
	use ApiTrait;

	public function __construct( ApiQuery $query, string $moduleName ) {
		parent::__construct( $query, $moduleName, 'cb' );
	}

	public function execute() {
		$result = $this->getResult();
		$services = MediaWikiServices::getInstance();
		$engine = $services->getSearchEngineFactory()->create();
		if ( !( $engine instanceof CirrusSearch ) ) {
			throw new \RuntimeException( 'Could not create cirrus engine' );
		}

		$builders = $this->getParameter( 'builders' );
		$profile = $this->getParameter( 'limiterprofile' );
		$flags = 0;
		if ( !in_array( 'content', $builders ) ) {
			$flags |= BuildDocument::SKIP_PARSE;
		}
		if ( !in_array( 'links', $builders ) ) {
			$flags |= BuildDocument::SKIP_LINKS;
		}

		$pages = [];
		$wikiPageFactory = $services->getWikiPageFactory();
		$revisionStore = $services->getRevisionStore();
		$revisionBased = false;
		if ( $this->getPageSet()->getRevisionIDs() ) {
			$revisionBased = true;
			foreach ( $this->getRevisionIDs() as $pageId => $revId ) {
				$rev = $revisionStore->getRevisionById( $revId );
				if ( $rev === null ) {
					// We cannot trust ApiPageSet to properly identify missing revisions, RevisionStore
					// might not agree with it likely because they could be using different db replicas (T370770)
					$result->addValue( 'query', 'badrevids', [
						$revId => [ 'revid' => $revId, 'missing' => true ]
					] );
				} elseif ( $rev->audienceCan( $rev::DELETED_TEXT, $rev::FOR_PUBLIC ) ) {
					// Redirects are not directly represented as searchable documents.
					// They are unrenderable.
					if ( $rev->getContent( SlotRecord::MAIN )->isRedirect() ) {
						$this->markUnrenderable( $result, $pageId );
					} else {
						$pages[$pageId] = $rev;
					}
				} else {
					// While the user might have permissions, we want to limit
					// what could possibly be indexed to that which is public.
					// For an anon this would fail deeper in the system
					// anyways, this early check mostly avoids blowing up deep
					// in the bowels.
					$result->addValue(
						[ 'query', 'pages', $pageId ],
						'texthidden', true
					);
				}
			}
		} else {
			foreach ( $this->getPageSet()->getGoodPages() as $pageId => $title ) {
				$page = $wikiPageFactory->newFromTitle( $title );
				if ( $page->isRedirect() ) {
					$this->markUnrenderable( $result, $pageId );
				} else {
					$pages[$pageId] = $page;
				}
			}
		}

		$searchConfig = $engine->getConfig();
		$builder = new BuildDocument(
			$this->getCirrusConnection(),
			$this->getDB(),
			$services->getRevisionStore(),
			$services->getBacklinkCacheFactory(),
			new DocumentSizeLimiter( $searchConfig->getProfileService()
				->loadProfile( SearchProfileService::DOCUMENT_SIZE_LIMITER, SearchProfileService::CONTEXT_DEFAULT, $profile ) ),
			$services->getTitleFormatter(),
			$services->getWikiPageFactory(),
			$services->getTitleFactory()
		);
		$baseMetadata = [];
		$clusterGroup = $searchConfig->getClusterAssignment()->getCrossClusterName();
		if ( $clusterGroup !== null ) {
			$baseMetadata['cluster_group'] = $clusterGroup;
		}
		$docs = $builder->initialize( $pages, $flags );
		foreach ( $docs as $pageId => $doc ) {
			$pageId = $doc->get( 'page_id' );
			$revision = $revisionBased ? $pages[$pageId] : null;
			if ( $builder->finalize( $doc, false, $revision ) ) {
				$result->addValue(
					[ 'query', 'pages', $pageId ],
					'cirrusbuilddoc', $doc->getData()
				);
				$hints = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
				$metadata = [];
				if ( $hints !== null ) {
					$metadata = $baseMetadata + [ 'noop_hints' => $hints ];
				}
				$limiterStats = CirrusIndexField::getHint( $doc, DocumentSizeLimiter::HINT_DOC_SIZE_LIMITER_STATS );
				if ( $limiterStats !== null ) {
					$metadata += [ 'size_limiter_stats' => $limiterStats ];
				}
				$indexName = $this->getCirrusConnection()->getIndexName( $searchConfig->get( SearchConfig::INDEX_BASE_NAME ),
					$this->getCirrusConnection()->getIndexSuffixForNamespace( $doc->get( 'namespace' ) ) );
				$metadata += [
					'index_name' => $indexName
				];

				$result->addValue( [ 'query', 'pages', $pageId ],
					'cirrusbuilddoc_metadata', $metadata );
				$result->addValue(
					[ 'query', 'pages', $pageId ],
					'cirrusbuilddoc_comment',
					'The CirrusDoc format is meant for internal use by CirrusSearch for debugging or queries, '
					. 'it might change at any time without notice'
				);
			}
		}
	}

	private function getRevisionIDs(): array {
		$result = [];
		$warning = false;
		foreach ( $this->getPageSet()->getRevisionIDs() as $revId => $pageId ) {
			if ( isset( $result[$pageId] ) ) {
				$warning = true;
				if ( $result[$pageId] >= $revId ) {
					continue;
				}
			}
			$result[$pageId] = $revId;
		}
		if ( $warning ) {
			$this->addWarning( [ 'apiwarn-cirrus-ignore-revisions' ] );
		}
		return $result;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'builders' => [
				ParamValidator::PARAM_DEFAULT => [ 'content', 'links' ],
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ALLOW_DUPLICATES => false,
				ParamValidator::PARAM_TYPE => [
					'content',
					'links',
				],
				ApiBase::PARAM_HELP_MSG => 'apihelp-query+cirrusbuilddoc-param-builders',
			],
			'limiterprofile' => [
				ParamValidator::PARAM_TYPE => 'string'
			],
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
			'action=query&prop=cirrusbuilddoc&titles=Main_Page' =>
				'apihelp-query+cirrusbuilddoc-example'
		];
	}

	/**
	 * @param ApiResult $result Result obect to write to
	 * @param int $pageId The page to mark unrenderable
	 */
	private function markUnrenderable( ApiResult $result, int $pageId ) {
		$result->addValue(
			[ 'query', 'pages', $pageId ],
			'unrenderable', true
		);
	}

}
