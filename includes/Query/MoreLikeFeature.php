<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Title;
use WikiPage;

class MoreLikeFeature implements KeywordFeature {
	const MORE_LIKE_THIS_PREFIX = 'morelike:';
	const MORE_LIKE_THIS_JUST_WIKIBASE_PREFIX = 'morelikewithwikibase:';

	const MORE_LIKE_THESE_NONE = 0;
	const MORE_LIKE_THESE_ONLY_WIKIBASE = 1;

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
	 * Greedily match the entire $term as a morelike query.
	 *
	 * @param SearchContext $context
	 * @param string $term
	 * @return string
	 */
	public function apply( SearchContext $context, $term ) {
		$keywords = [
			self::MORE_LIKE_THESE_NONE => self::MORE_LIKE_THIS_PREFIX,
			self::MORE_LIKE_THESE_ONLY_WIKIBASE => self::MORE_LIKE_THIS_JUST_WIKIBASE_PREFIX,
		];
		foreach ( $keywords as $options => $prefix ) {
			$pos = strpos( $term, $prefix );
			// Currently requires morelike to be first (and only) feature, to match
			// behaviour prior to the keyword feature refactor. Allowing other
			// keywords works fine, but there are some problems to be worked
			// out for combined text + morelike queries.
			//
			// When removing this restriction also need to consider how stable
			// the query output is, such that when the query is hashed for the
			// application side query cache the hashes are the same regardless
			// of input order when the result would be the same.
			if ( $pos === 0 ) {
				$titleString = substr( $term, $pos + strlen( $prefix ) );
				$this->doApply( $context, $titleString, $options );

				return "";
			}
		}

		// No keyword given
		return $term;
	}

	private function doApply( SearchContext $context, $titleString, $options ) {
		$titles = $this->collectTitles( $titleString );
		if ( !count( $titles ) ) {
			$context->setResultsPossible( false );
			return;
		}
		$query = $this->buildMoreLikeQuery( $context, $titles, $options );
		if ( $query === null ) {
			$context->setResultsPossible( false );
			return;
		}

		// @todo Does this cause problems with other keywords?
		$context->setMainQuery( $query );
		$context->setCacheTtl( $this->config->get( 'CirrusSearchMoreLikeThisTTL' ) );

		$context->addSyntaxUsed( 'more_like' );
		// @todo this isn't guaranteed, another keyword could override it.  We
		// should probably transition to some scheme that inspects syntax used
		// and decides a search type?
		$context->setSearchType( 'more_like' );
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
	 * @param int $options
	 * @return \Elastica\Query\MoreLikeThis|null
	 */
	private function buildMoreLikeQuery( SearchContext $context, array $titles, $options ) {
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

		if ( $options & self::MORE_LIKE_THESE_ONLY_WIKIBASE ) {
			$context->addFilter( new \Elastica\Query\Exists( 'wikibase_item' ) );
		}

		// highlight snippets are not great so it's worth running a match all query
		// to save cpu cycles
		$context->setHighlightQuery( new \Elastica\Query\MatchAll() );

		return $query;
	}
}
