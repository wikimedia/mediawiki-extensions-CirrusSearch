<?php
namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Sparql\SparqlClient;
use MediaWiki\Sparql\SparqlException;
use Title;

/**
 * Filters by category or its subcategories. E.g. if category Vehicles includes Cars
 * and Boats, then search for Vehicles would match pages in Vehicles, Cars and Boats.
 *
 * Syntax:
 *  deepcat:Vehicles
 */
class DeepcatFeature extends SimpleKeywordFeature {
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
	 * @var string
	 */
	private $prefix;
	/**
	 * @var SparqlClient
	 */
	private $client;

	/**
	 * User agent to use for SPARQL queries
	 */
	const USER_AGENT = 'CirrusSearch deepcat feature';
	/**
	 * Timeout (in seconds) for SPARQL query.
	 * TODO: make configurable?
	 */
	const TIMEOUT = 3;
	/**
	 * Stats key for SPARQL requests
	 */
	const STATSD_SPARQL_KEY = 'CirrusSearch.deepcat.sparql';
	/**
	 * Stats key for reporting too many categories
	 */
	const STATSD_TOOMANY_KEY = 'CirrusSearch.deepcat.toomany';

	/**
	 * @param Config $config
	 * @param SparqlClient $client
	 */
	public function __construct( Config $config, SparqlClient $client ) {
		$this->depth = (int)$config->get( 'CirrusSearchCategoryDepth' );
		$this->limit = (int)$config->get( 'CirrusSearchCategoryMax' );
		$this->prefix = $this->getCategoryPrefix();
		$endpoint = $config->get( 'CirrusSearchCategoryEndpoint' );
		if ( !empty( $endpoint ) ) {
			$this->client = $client;
		}
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
		if ( empty( $this->client ) ) {
			$context->addWarning( 'cirrussearch-feature-deepcat-endpoint' );
			return [ null, false ];
		}

		$startQueryTime = microtime( true );
		try {
			$categories = $this->fetchCategories( $value, $context );
		} catch ( SparqlException $e ) {
			// Not publishing exception here because it can contain too many details including IPs, etc.
			$context->addWarning( 'cirrussearch-feature-deepcat-exception' );
			LoggerFactory::getInstance( 'CirrusSearch' )
				->warning( 'Deepcat SPARQL Exception: ' . $e->getMessage() );
			$categories = [ $value ];
		}
		$this->logRequest( $startQueryTime );

		if ( empty( $categories ) ) {
			return [ null, false ];
		}

		$filter = new \Elastica\Query\BoolQuery();
		foreach ( $categories as $cat ) {
			$filter->addShould( QueryHelper::matchPage( 'category.lowercase_keyword', $cat ) );
		}

		return [ $filter, false ];
	}

	/**
	 * Get URL prefix for full category URL for this wiki.
	 * @return bool|string
	 */
	private function getCategoryPrefix() {
		$title = Title::makeTitle( NS_CATEGORY, 'ZZ' );
		$fullName = $title->getFullURL( '', false, PROTO_CANONICAL );
		return substr( $fullName, 0, - 2 );
	}

	/**
	 * Record stats data for the request.
	 * @param float $startQueryTime
	 */
	private function logRequest( $startQueryTime ) {
		$timeTaken = intval( 1000 * ( microtime( true ) - $startQueryTime ) );
		MediaWikiServices::getInstance()->getStatsdDataFactory()->timing(
			self::STATSD_SPARQL_KEY, $timeTaken
		);
	}

	/**
	 * Get child categories using SPARQL service.
	 * @param string $rootCategory Category to start looking from
	 * @return string[] List of subcategories.
	 * Note that the list may be incomplete due to limitations of the service.
	 * @throws SparqlException
	 */
	private function fetchCategories( $rootCategory, SearchContext $context ) {
		/** @var SparqlClient $client */
		$title = Title::makeTitle( NS_CATEGORY, $rootCategory );
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
			$context->addWarning( 'cirrussearch-feature-deepcat-toomany' );
			MediaWikiServices::getInstance()
				->getStatsdDataFactory()
				->increment( self::STATSD_TOOMANY_KEY );
			$context->setResultsPossible( false );
			return [];
		}

		$prefixLen = strlen( $this->prefix );
		return array_map( function ( $row ) use ( $prefixLen ) {
			// TODO: maybe we want to check the prefix is indeed the same?
			// It should be but who knows...
			return substr( $row['out'], $prefixLen );
		}, $result );
	}

}
