<?php

namespace CirrusSearch\Query;

use CirrusSearch;
use CirrusSearch\Connection;
use CirrusSearch\Search\SearchContext;

/**
 * Handles the prefix: keyword for matching titles. Can be used to
 * specify a namespace, a prefix of the title, or both. Note that
 * unlike other keyword features this greedily uses everything after
 * the prefix: keyword, so must be used at the end of the query. Also
 * note that this will override namespace filters previously applied
 * to the SearchContext.
 *
 * Examples:
 *   prefix:Calif
 *   prefix:Talk:
 *   prefix:Talk:Calif
 *   prefix:California Cou
 *   prefix:"California Cou"
 */
class PrefixFeature implements KeywordFeature {

	/**
	 * @var Connection
	 */
	private $connection;

	public function __construct( Connection $conn ) {
		$this->connection = $conn;
	}

	/**
	 * @param SearchContext $context
	 * @param string $term
	 * @return string
	 */
	public function apply( SearchContext $context, $term ) {
		$prefixPos = strpos( $term, 'prefix:' );
		if ( $prefixPos === false ) {
			return $term;
		}

		$value = substr( $term, 7 + $prefixPos );
		// Trim quotes in case the user wanted to quote the prefix
		$value = trim( $value, '"' );
		if ( strlen( $value ) === 0 ) {
			return $term;
		}

		$context->addSyntaxUsed( 'prefix' );
		$context->addSuggestSuffix( ' prefix:' . $value );

		// Suck namespaces out of $value. Note that this overrides provided
		// namespace filters.
		$cirrusSearchEngine = new CirrusSearch();
		$cirrusSearchEngine->setConnection( $this->connection );
		$value = trim( $cirrusSearchEngine->replacePrefixes( $value ) );
		$context->setNamespaces( $cirrusSearchEngine->namespaces );

		// If the namespace prefix wasn't the entire prefix filter then add a filter for the title
		if ( strpos( $value, ':' ) !== strlen( $value ) - 1 ) {
			$value = str_Replace( '_', ' ', $value );
			$prefixQuery = new \Elastica\Query\Match();
			$prefixQuery->setFieldQuery( 'title.prefix', $value );
			$context->addFilter( $prefixQuery );
		}

		return substr( $term, 0, max( 0, $prefixPos - 1 ) );
	}
}
