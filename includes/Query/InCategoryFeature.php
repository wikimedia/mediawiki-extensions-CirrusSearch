<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\PageStore;

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
class InCategoryFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	/**
	 * @var int
	 */
	private $maxConditions;
	/**
	 * @var PageStore|null
	 */
	private $pageStore;

	/**
	 * @param Config $config
	 * @param PageStore|null $pageStore
	 */
	public function __construct( Config $config, ?PageStore $pageStore = null ) {
		$this->maxConditions = $config->get( 'CirrusSearchMaxIncategoryOptions' );
		$this->pageStore = $pageStore;
	}

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'incategory' ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		if ( empty( $node->getParsedValue()['pageIds'] ) ) {
			// We depend on the db to fetch the category by id
			return CrossSearchStrategy::allWikisStrategy();
		} else {
			return CrossSearchStrategy::hostWikiOnlyStrategy();
		}
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
		$parsedValue = $this->parseValue( $key, $value, $quotedValue, '', '', $context );
		if ( $parsedValue === null ) {
			$context->setResultsPossible( false );
			return [ null, false ];
		}

		$names = $this->doExpand( $key, $parsedValue, $context );

		if ( $names === [] ) {
			$context->setResultsPossible( false );
			return [ null, false ];
		}

		$filter = $this->matchPageCategories( $names );
		return [ $filter, false ];
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param string $valueDelimiter
	 * @param string $suffix
	 * @param WarningCollector $warningCollector
	 * @return array|false|null
	 */
	public function parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix, WarningCollector $warningCollector ) {
		// en:Programming|id:3041512\
		$categories = explode( '|', $value );// en:programming
		if ( count( $categories ) > $this->maxConditions ) {
			$warningCollector->addWarning(
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

		$pageIds = [];
		$names = [];

		foreach ( $categories as $category ) {
			if ( str_starts_with( $category, 'id:' ) ) {
				$pageId = substr( $category, 3 );
				if ( ctype_digit( $pageId ) ) {
					$pageIds[] = $pageId;
				}
			} else {
				$names[] = $category;// en:programming
			}
		}

		return [ 'names' => $names, 'pageIds' => $pageIds ];// en:programming
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param SearchConfig $config
	 * @param WarningCollector $warningCollector
	 * @return array
	 */
	public function expand( KeywordFeatureNode $node, SearchConfig $config, WarningCollector $warningCollector ) {
		return $this->doExpand( $node->getKey(), $node->getParsedValue(), $warningCollector );
	}

	/**
	 * @param string $key
	 * @param array $parsedValue
	 * @param WarningCollector $warningCollector
	 * @return array
	 */
	private function doExpand( $key, array $parsedValue, WarningCollector $warningCollector ) {
		$names = $parsedValue['names'];// en:programming
		$pageIds = $parsedValue['pageIds'];

		$pageStore = $this->pageStore ?? MediaWikiServices::getInstance()->getPageStore();
		$titles = $pageStore
			->newSelectQueryBuilder()
			->wherePageIds( $pageIds )
			->caller( __METHOD__ )
			->fetchPageRecords();

		$titleFormatter = MediaWikiServices::getInstance()->getTitleFormatter();

		/** @var PageRecord $title */
		foreach ( $titles as $title ) {
			$names[] = $titleFormatter->getText( $title );
		}

		if ( $names === [] ) {
			$warningCollector->addWarning( 'cirrussearch-incategory-feature-no-valid-categories', $key );
		}
		return $names;// en:programing
	}

	/**
	 * Builds an or between many categories that the page could be in.
	 *
	 * @param string[] $names categories to match
	 * @return \Elastica\Query\BoolQuery|null A null return value means all values are filtered
	 *  and an empty result set should be returned.
	 */
	private function matchPageCategories( array $names ) {
		$filter = new \Elastica\Query\BoolQuery();

		foreach ( $names as $name ) {
			$filter->addShould( QueryHelper::matchCategory( 'category.lowercase_keyword', $name ) );
		}

		return $filter;
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		$names = $context->getKeywordExpandedData( $node );
		if ( $names === [] ) {
			return null;
		}
		return $this->matchPageCategories( $names );
	}
}
