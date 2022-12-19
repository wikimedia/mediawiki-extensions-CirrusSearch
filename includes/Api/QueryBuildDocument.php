<?php

namespace CirrusSearch\Api;

use ApiBase;
use CirrusSearch\BuildDocument\BuildDocument;
use CirrusSearch\BuildDocument\DocumentSizeLimiter;
use CirrusSearch\CirrusSearch;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\CirrusIndexField;
use Mediawiki\MediaWikiServices;
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
class QueryBuildDocument extends \ApiQueryBase {
	use ApiTrait;

	public function __construct( \ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'cb' );
	}

	public function execute() {
		$result = $this->getResult();
		$services = MediaWikiServices::getInstance();
		$engine = $services->getSearchEngineFactory()->create();

		$builders = $this->getParameter( 'builders' );
		$profile = $this->getParameter( 'limiterprofile' );
		$flags = 0;
		if ( !in_array( 'content', $builders ) ) {
			$flags |= BuildDocument::SKIP_PARSE;
		}
		if ( !in_array( 'links', $builders ) ) {
			$flags |= BuildDocument::SKIP_LINKS;
		}

		if ( $engine instanceof CirrusSearch ) {
			$pages = [];
			$wikiPageFactory = $services->getWikiPageFactory();
			$revisionStore = $services->getRevisionStore();
			$revisionBased = false;
			if ( $this->getPageSet()->getRevisionIDs() ) {
				$revisionBased = true;
				foreach ( $this->getPageSet()->getRevisionIDs() as $revId => $pageId ) {
					$pages[$revId] = $revisionStore->getRevisionById( $revId );
				}
			} else {
				foreach ( $this->getPageSet()->getGoodPages() as $pageId => $title ) {
					$pages[$pageId] = $wikiPageFactory->newFromTitle( $title );
				}
			}

			$builder = new BuildDocument(
				$this->getCirrusConnection(),
				$this->getDB(),
				$services->getParserCache(),
				$services->getRevisionStore(),
				$services->getBacklinkCacheFactory(),
				new DocumentSizeLimiter( $engine->getConfig()->getProfileService()
					->loadProfile( SearchProfileService::DOCUMENT_SIZE_LIMITER, SearchProfileService::CONTEXT_DEFAULT, $profile ) ),
				$services->getTitleFormatter(),
				$services->getWikiPageFactory()
			);
			$baseMetadata = [];
			$clusterGroup = $engine->getConfig()->getClusterAssignment()->getCrossClusterName();
			if ( $clusterGroup !== null ) {
				$baseMetadata['cluster_group'] = $clusterGroup;
			}
			$docs = $builder->initialize( $pages, $flags );
			foreach ( $docs as $pageId => $doc ) {
				$revisionId = $doc->get( 'version' );
				$revision = $revisionBased ? $pages[$revisionId] : null;
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

					$result->addValue( [ 'query', 'pages', $pageId ],
						'cirrusbuilddoc_metadata', $metadata );
				}
			}
		} else {
			throw new \RuntimeException( 'Could not create cirrus engine' );
		}
	}

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
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&prop=cirrusbuilddoc&titles=Main_Page' =>
				'apihelp-query+cirrusbuilddoc-example'
		];
	}

}
