<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Title;
use WikiPage;

class MoreLikeFeature extends SimpleKeywordFeature {
	const MORE_LIKE_THIS = 'morelike';
	const MORE_LIKE_THIS_JUST_WIKIBASE = 'morelikewithwikibase';

	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @param SearchConfig $config
	 */
	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * TODO: switch to non-greedy
	 * @return bool
	 */
	public function greedy() {
		return true;
	}

	/**
	 * morelike is only allowed at the beginning of the query
	 * TODO: allow morelike everywhere
	 * @return bool
	 */
	public function queryHeader() {
		return true;
	}

	protected function getKeywords() {
		return [ self::MORE_LIKE_THIS, self::MORE_LIKE_THIS_JUST_WIKIBASE ];
	}

	/**
	 * @param string $key
	 * @param string $valueDelimiter
	 * @return string
	 */
	public function getFeatureName( $key, $valueDelimiter ) {
		return "more_like";
	}

	/**
	 * @param SearchContext $context
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param bool $negated
	 * @return array|void
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$context->setCacheTtl( $this->config->get( 'CirrusSearchMoreLikeThisTTL' ) );
		$titles = $this->collectTitles( $value );
		if ( !count( $titles ) ) {
			$context->addWarning(
				"cirrussearch-mlt-feature-no-valid-titles",
				$key
			);
			$context->setResultsPossible( false );
			return;
		}
		$query = $this->buildMoreLikeQuery( $context, $titles );
		if ( $query === null ) {
			$context->addWarning(
				"cirrussearch-mlt-not-configured",
				$key
			);
			$context->setResultsPossible( false );
			return;
		}

		// FIXME: this erases the main query making it impossible to combine with
		// other keywords/search query
		$context->setMainQuery( $query );
		$wbFilter = null;

		if ( $key === self::MORE_LIKE_THIS_JUST_WIKIBASE ) {
				$wbFilter = new \Elastica\Query\Exists( 'wikibase_item' );
		}
		return [ $wbFilter, false ];
	}

	/**
	 * @param string $term
	 * @return Title[]
	 */
	private function collectTitles( $term ) {
		if ( $this->config->getElement( 'CirrusSearchDevelOptions',
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
	 * @return Title[]
	 */
	private function collectTitlesFromElastic( $terms ) {
		$titles = [];
		foreach ( explode( '|', $terms ) as $term ) {
			$title = null;
			\CirrusSearch\Hooks::onSearchGetNearMatch( $term, $title );
			if ( $title != null ) {
				$titles[] = $title;
			}
		}
		return $titles;
	}

	/**
	 * @param string $term
	 * @return Title[]
	 */
	private function collectTitlesFromDB( $term ) {
		$titles = [];
		$found = [];
		foreach ( explode( '|', $term ) as $title ) {
			$title = Title::newFromText( trim( $title ) );
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
				$page = WikiPage::factory( $title );
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
	 * @param SearchContext $context
	 * @param Title[] $titles
	 * @return \Elastica\Query\MoreLikeThis|null
	 */
	private function buildMoreLikeQuery( SearchContext $context, array $titles ) {
		sort( $titles, SORT_STRING );
		$docIds = [];
		$likeDocs = [];
		foreach ( $titles as $title ) {
			$docId = $this->config->makeId( $title->getArticleID() );
			$docIds[] = $docId;
			$likeDocs[] = [ '_id' => $docId ];
		}

		// If no fields have been set we return no results. This can happen if
		// the user override this setting with field names that are not allowed
		// in $this->config->get( 'CirrusSearchMoreLikeThisAllowedFields' )
		// (see Hooks.php)
		if ( !$this->config->get( 'CirrusSearchMoreLikeThisFields' ) ) {
			return null;
		}

		$moreLikeThisFields = $this->config->get( 'CirrusSearchMoreLikeThisFields' );
		sort( $moreLikeThisFields );
		$query = new \Elastica\Query\MoreLikeThis();
		$query->setParams( $this->config->get( 'CirrusSearchMoreLikeThisConfig' ) );
		$query->setFields( $moreLikeThisFields );

		/** @suppress PhanTypeMismatchArgument library is mis-annotated */
		$query->setLike( $likeDocs );

		// highlight snippets are not great so it's worth running a match all query
		// to save cpu cycles
		$context->setHighlightQuery( new \Elastica\Query\MatchAll() );

		return $query;
	}
}
