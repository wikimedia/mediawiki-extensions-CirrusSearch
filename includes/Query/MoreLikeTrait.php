<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\Connection;
use CirrusSearch\Hooks;
use CirrusSearch\SearchConfig;
use CirrusSearch\WarningCollector;
use Elastica\Query\MoreLikeThis;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;

trait MoreLikeTrait {
	/**
	 * @param string $key
	 * @param string $term
	 * @param WarningCollector $warningCollector
	 * @return PageIdentity[]
	 */
	protected function doExpand( $key, $term, WarningCollector $warningCollector ) {
		// If no fields have been set we return no results. This can happen if
		// the user override this setting with field names that are not allowed
		// in $this->getConfig()->get( 'CirrusSearchMoreLikeThisAllowedFields' )
		// (see Hooks.php)
		if ( !$this->getConfig()->get( CirrusConfigNames::MoreLikeThisFields ) ) {
			$warningCollector->addWarning( "cirrussearch-mlt-not-configured", $key );
			return [];
		}
		$titles = $this->collectTitles( $term );
		if ( $titles === [] ) {
			$warningCollector->addWarning( "cirrussearch-mlt-feature-no-valid-titles", $key );
		}
		return $titles;
	}

	/**
	 * @param string $term
	 * @return PageIdentity[]
	 */
	private function collectTitles( $term ) {
		if ( $this->getConfig()->getElement( CirrusConfigNames::DevelOptions,
			'morelike_collect_titles_from_elastic' )
		) {
			return $this->collectTitlesFromElastic( $term );
		} else {
			return $this->collectTitlesFromDB( $term );
		}
	}

	/**
	 * Use for devel purpose only
	 * @param string $terms
	 * @return PageIdentity[]
	 */
	private function collectTitlesFromElastic( $terms ) {
		$titles = [];
		foreach ( explode( '|', $terms ) as $term ) {
			$title = null;
			Hooks::handleSearchGetNearMatch( $term, $title );
			if ( $title != null ) {
				$titles[] = $title;
			}
		}
		return $titles;
	}

	/**
	 * @param string $term
	 * @return PageIdentity[]
	 */
	private function collectTitlesFromDB( $term ) {
		$titles = [];
		$found = [];
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		foreach ( explode( '|', $term ) as $title ) {
			$title = $titleFactory->newFromText( trim( $title ) );
			while ( true ) {
				if ( !$title ) {
					continue 2;
				}
				$titleText = $title->getFullText();
				if ( isset( $found[$titleText] ) ) {
					continue 2;
				}
				$found[$titleText] = true;
				if ( !$title->exists() ) {
					continue 2;
				}
				if ( !$title->isRedirect() ) {
					break;
				}
				// If the page was a redirect loop the while( true ) again.
				$page = $wikiPageFactory->newFromTitle( $title );
				if ( !$page->exists() ) {
					continue 2;
				}
				$title = $page->getRedirectTarget();
			}
			$titles[] = $title;
		}

		return $titles;
	}

	/**
	 * Builds a more like this query for the specified titles. Take care that
	 * this outputs a stable result, regardless of order of configuration
	 * parameters and input titles. The result of this is hashed to generate an
	 * application side cache key. If the result is unstable we will see a
	 * reduced hit rate, and waste cache storage space.
	 *
	 * @param PageIdentity[] $titles
	 * @return MoreLikeThis
	 */
	protected function buildMoreLikeQuery( array $titles ) {
		sort( $titles, SORT_STRING );
		$likeDocs = [];
		// We pull a connection object to access index names, ideally we should not require this
		// since we actually don't make any connection but these methods have been available there
		// for historical reasons.
		$connection = new Connection( $this->getConfig() );
		$indexBaseName = $this->getConfig()->get( CirrusConfigNames::IndexBaseName );
		foreach ( $titles as $title ) {
			$docId = $this->getConfig()->makeId( $title->getId() );

			$likeDocs[] = [
				'_id' => $docId,
				'_index' => $connection->getIndexName( $indexBaseName, $connection->getIndexSuffixForNamespace( $title->getNamespace() ) )
			];
		}

		$moreLikeThisFields = $this->getConfig()->get( CirrusConfigNames::MoreLikeThisFields );
		sort( $moreLikeThisFields );
		$query = new MoreLikeThis();
		$query->setParams( $this->getConfig()->get( CirrusConfigNames::MoreLikeThisConfig ) );
		$query->setFields( $moreLikeThisFields );

		/** @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal library is mis-annotated */
		$query->setLike( $likeDocs );

		return $query;
	}

	/**
	 * @return SearchConfig
	 */
	abstract public function getConfig(): SearchConfig;
}
