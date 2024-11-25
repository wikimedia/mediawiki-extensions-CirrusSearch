<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use CirrusSearch\Util;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Sparql\SparqlClient;
use MediaWiki\Sparql\SparqlException;
use MediaWiki\Title\Title;

/**
 * Filters by category or its subcategories. E.g. if category Vehicles includes Cars
 * and Boats, then search for Vehicles would match pages in Vehicles, Cars and Boats.
 *
 * Syntax:
 *  deepcat:Vehicles
 */
class DeepcatFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	/**
	 * Max lookup depth
	 * @var int
	 */
	private $depth;
	/**
	 * Max number of categories
	 * @var int
	 */
	private $limit;
	/**
	 * Category URL prefix for this wiki
	 * @var string|null (lazy loaded)
	 */
	private $prefix;
	/**
	 * @var SparqlClient
	 */
	private $client;

	/**
	 * User agent to use for SPARQL queries
	 */
	public const USER_AGENT = 'CirrusSearch deepcat feature';
	/**
	 * Timeout (in seconds) for SPARQL query.
	 * TODO: make configurable?
	 */
	public const TIMEOUT = 3;

	/**
	 * @param Config $config
	 * @param SparqlClient|null $client
	 */
	public function __construct( Config $config, ?SparqlClient $client = null ) {
		$this->depth = (int)$config->get( 'CirrusSearchCategoryDepth' );
		$this->limit = (int)$config->get( 'CirrusSearchCategoryMax' );
		$endpoint = $config->get( 'CirrusSearchCategoryEndpoint' );
		if ( $endpoint !== null && $endpoint !== '' ) {
			$this->client = $client ?: MediaWikiServices::getInstance()->getService( 'CirrusCategoriesClient' );
		}
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		// the category tree is wiki specific
		return CrossSearchStrategy::hostWikiOnlyStrategy();
	}

	/**
	 * @return string[] The list of keywords this feature is supposed to match
	 */
	protected function getKeywords() {
		return [ 'deepcat', 'deepcategory' ];
	}

	/**
	 * @param string $key
	 * @param string $valueDelimiter
	 * @return string
	 */
	public function getFeatureName( $key, $valueDelimiter ) {
		return 'deepcategory';
	}

	/**
	 * Applies the detected keyword from the search term. May apply changes
	 * either to $context directly, or return a filter to be added.
	 *
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped and escaped
	 *  quotes un-escaped.
	 * @param string $quotedValue The original value in the search string, including quotes if used
	 * @param bool $negated Is the search negated? Not used to generate the returned AbstractQuery,
	 *  that will be negated as necessary. Used for any other building/context necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$filter = $this->doGetFilterQuery( $this->doExpand( $value, $context ) );
		if ( $filter === null ) {
			$context->setResultsPossible( false );
		}

		return [ $filter, false ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param SearchConfig $config
	 * @param WarningCollector $warningCollector
	 * @return array
	 */
	public function expand( KeywordFeatureNode $node, SearchConfig $config, WarningCollector $warningCollector ) {
		return $this->doExpand( $node->getValue(), $warningCollector );
	}

	/**
	 * @param string $value
	 * @param WarningCollector $warningCollector
	 * @return array
	 */
	private function doExpand( $value, WarningCollector $warningCollector ) {
		if ( !$this->client ) {
			$warningCollector->addWarning( 'cirrussearch-feature-deepcat-endpoint' );
			return [];
		}

		$startQueryTime = microtime( true );
		try {
			$categories = $this->fetchCategories( $value, $warningCollector );
		} catch ( SparqlException $e ) {
			// Not publishing exception here because it can contain too many details including IPs, etc.
			$warningCollector->addWarning( $this->decideUiWarning( $e ) );
			LoggerFactory::getInstance( 'CirrusSearch' )
				->warning( 'Deepcat SPARQL Exception: ' . $e->getMessage() );
			$categories = [ $value ];
		}
		$this->logRequest( $startQueryTime );
		return $categories;
	}

	private function decideUiWarning( SparqlException $e ): string {
		$message = $e->getMessage();
		// This could alternatively be a 500 error if blazegraph timed out
		// prior to the http client timing out, but that doesn't happen due
		// to http and blazegraph timeouts being set to the same value.
		if ( strpos( $message, 'HTTP request timed out.' ) !== false ) {
			return 'cirrussearch-feature-deepcat-timeout';
		} else {
			return 'cirrussearch-feature-deepcat-exception';
		}
	}

	/**
	 * Get URL prefix for full category URL for this wiki.
	 * @return bool|string
	 */
	private function getCategoryPrefix() {
		if ( $this->prefix === null ) {
			$title = Title::makeTitle( NS_CATEGORY, 'ZZ' );
			$fullName = $title->getFullURL( '', false, PROTO_CANONICAL );
			$this->prefix = substr( $fullName, 0, -2 );
		}
		return $this->prefix;
	}

	/**
	 * Record stats data for the request.
	 * @param float $startQueryTime
	 */
	private function logRequest( $startQueryTime ) {
		$timeTaken = intval( 1000 * ( microtime( true ) - $startQueryTime ) );
		Util::getStatsFactory()
			->getTiming( 'deepcat_sparql_query_seconds' )
			->copyToStatsdAt( 'CirrusSearch.deepcat.sparql' )
			->observe( $timeTaken );
	}

	/**
	 * Get child categories using SPARQL service.
	 * @param string $rootCategory Category to start looking from
	 * @param WarningCollector $warningCollector
	 * @return string[] List of subcategories.
	 * Note that the list may be incomplete due to limitations of the service.
	 * @throws SparqlException
	 */
	private function fetchCategories( $rootCategory, WarningCollector $warningCollector ) {
		$title = Title::makeTitleSafe( NS_CATEGORY, $rootCategory );
		if ( $title === null ) {
			$warningCollector->addWarning( 'cirrussearch-feature-deepcat-invalid-title' );
			return [];
		}
		$fullName = $title->getFullURL( '', false, PROTO_CANONICAL );
		$limit1 = $this->limit + 1;
		$query = <<<SPARQL
SELECT ?out WHERE {
      SERVICE mediawiki:categoryTree {
          bd:serviceParam mediawiki:start <$fullName> .
          bd:serviceParam mediawiki:direction "Reverse" .
          bd:serviceParam mediawiki:depth {$this->depth} .
      }
} ORDER BY ASC(?depth)
LIMIT $limit1
SPARQL;
		$result = $this->client->query( $query );

		if ( count( $result ) > $this->limit ) {
			// We went over the limit.
			// According to T181549 this means we fail the filter application
			$warningCollector->addWarning( 'cirrussearch-feature-deepcat-toomany' );
			Util::getStatsFactory()
				->getCounter( 'deepcat_too_many_total' )
				->copyToStatsdAt( 'CirrusSearch.deepcat.toomany' )
				->increment();
			$result = array_slice( $result, 0, $this->limit );
		}

		$prefixLen = strlen( $this->getCategoryPrefix() );
		return array_map( static function ( $row ) use ( $prefixLen ) {
			// TODO: maybe we want to check the prefix is indeed the same?
			// It should be but who knows...
			return rawurldecode( substr( $row['out'], $prefixLen ) );
		}, $result );
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		return $this->doGetFilterQuery( $context->getKeywordExpandedData( $node ) );
	}

	/**
	 * @param array $categories
	 * @return \Elastica\Query\BoolQuery|null
	 */
	protected function doGetFilterQuery( array $categories ) {
		if ( $categories == [] ) {
			return null;
		}

		$filter = new \Elastica\Query\BoolQuery();
		foreach ( $categories as $cat ) {
			$filter->addShould( QueryHelper::matchPage( 'category.lowercase_keyword', $cat ) );
		}

		return $filter;
	}
}
