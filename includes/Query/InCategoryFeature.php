<?php

namespace CirrusSearch\Query;

use Config;
use CirrusSearch\Search\SearchContext;
use Title;

/**
 * Filters by one or more categories, specified either by name or by category
 * id. Multiple categories are separated by |. Categories specified by id
 * must follow the syntax `id:<id>`.
 *
 * We emulate template syntax here as best as possible, so things in NS_MAIN
 * are prefixed with ":" and things in NS_TEMPLATE don't have a prefix at all.
 * Since we don't actually index templates like that, munge the query here.
 *
 * Examples:
 *   incategory:id:12345
 *   incategory:Music_by_genre
 *   incategory:Music_by_genre|Animals
 *   incategory:"Music by genre|Animals"
 *   incategory:Animals|id:54321
 *   incategory::Something_in_NS_MAIN
 */
class InCategoryFeature extends SimpleKeywordFeature {
	/**
	 * @var int
	 */
	private $maxConditions;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->maxConditions = $config->get( 'CirrusSearchMaxIncategoryOptions' );
	}

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'incategory' ];
	}

	/**
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped
	 * @param string $quotedValue The original value in the search string, including quotes if used
	 * @param bool $negated Is the search negated? Not used to generate the returned AbstractQuery,
	 *  that will be negated as necessary. Used for any other building/context necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$categories = explode( '|', $value );
		if ( count( $categories ) > $this->maxConditions ) {
			$context->addWarning(
				'cirrussearch-feature-too-many-conditions',
				$key,
				$this->maxConditions
			);
			$categories = array_slice(
				$categories,
				0,
				$this->maxConditions
			);
		}
		$filter = $this->matchPageCategories( $categories );
		if ( $filter === null ) {
			$context->setResultsPossible( false );
			$context->addWarning(
				'cirrussearch-incategory-feature-no-valid-categories',
				$key
			);
		}

		return [ $filter, false ];
	}

	/**
	 * Builds an or between many categories that the page could be in.
	 *
	 * @param string[] $categories categories to match
	 * @return \Elastica\Query\BoolQuery|null A null return value means all values are filtered
	 *  and an empty result set should be returned.
	 */
	private function matchPageCategories( array $categories ) {
		$filter = new \Elastica\Query\BoolQuery();
		$pageIds = [];
		$names = [];
		foreach ( $categories as $category ) {
			if ( substr( $category, 0, 3 ) === 'id:' ) {
				$pageId = substr( $category, 3 );
				if ( ctype_digit( $pageId ) ) {
					$pageIds[] = $pageId;
				}
			} else {
				$names[] = $category;
			}
		}

		foreach ( Title::newFromIDs( $pageIds ) as $title ) {
			$names[] = $title->getText();
		}
		if ( !$names ) {
			return null;
		}
		foreach ( $names as $name ) {
			$filter->addShould( QueryHelper::matchPage( 'category.lowercase_keyword', $name ) );
		}

		return $filter;
	}
}
