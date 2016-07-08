<?php

namespace CirrusSearch\Query;

use CirrusSearch\SearchConfig;
use CirrusSearch\Search\SearchContext;

/**
 * helpers for building queries
 */
class QueryHelper {
	/**
	 * @var bool Should the _all field be used when querying?
	 */
	private $useAllField;

	/**
	 * @var float[] Map from field name to float weight in the query
	 */
	private $searchWeights;

	public function __construct( SearchConfig $config ) {
		$this->useAllField = $config->getElement( 'CirrusSearchAllFields', 'use' );
		$this->searchWeights = $config->get( 'CirrusSearchWeights' );
	}

	/**
	 * Builds a match query against $field for $title. $title is munged to make
	 * title matching better more intuitive for users.
	 *
	 * @param string $field field containing the title
	 * @param string $title title query text to match against
	 * @param bool $underscores If the field contains underscores instead of
	 *  spaces. Defaults to false.
	 * @return \Elastica\Query\Match For matching $title to $field
	 */
	public static function matchPage( $field, $title, $underscores = false ) {
		if ( $underscores ) {
			$title = str_replace( ' ', '_', $title );
		} else {
			$title = str_replace( '_', ' ', $title );
		}
		$match = new \Elastica\Query\Match();
		$match->setFieldQuery( $field, $title );

		return $match;
	}

	/**
	 * Walks through an array of query pieces, as built by
	 * self::replacePartsOfQuery, and replaces all raw pieces by the result of
	 * self::replacePartsOfQuery when called with the provided regex and
	 * callable. One query piece may turn into one or more query pieces in the
	 * result.
	 *
	 * @param array[] $query The set of query pieces to apply against
	 * @param string $regex Pieces of $queryPart that match this regex will
	 *  be provided to $callable.
	 * @param callable $callable A function accepting the $matches from preg_match
	 *  and returning either a raw or escaped query piece.
	 * @return array[] The set of query pieces after applying regex and callable
	 */
	public static function replaceAllPartsOfQuery( array $query, $regex, $callable ) {
		$result = array();
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$result = array_merge( $result, self::replacePartsOfQuery( $queryPart[ 'raw' ], $regex, $callable ) );
			} else {
				$result[] = $queryPart;
			}
		}
		return $result;
	}

	/**
	 * Splits a query string into one or more sequential pieces. Each piece
	 * of the query can either be raw (['raw'=>'stuff']), or escaped
	 * (['escaped'=>'stuff']). escaped can also optionally include a nonAll
	 * query (['escaped'=>'stuff','nonAll'=>'stuff']). If nonAll is not set
	 * the escaped query will be used.
	 *
	 * Pieces of $queryPart that do not match the provided $regex are tagged
	 * as 'raw' and may see further parsing. $callable receives pieces of
	 * the string that match the regex and must return either a raw or escaped
	 * query piece.
	 *
	 * @param string $queryPart Raw piece of a user supplied query string
	 * @param string $regex Pieces of $queryPart that match this regex will
	 *  be provided to $callable.
	 * @param callable $callable A function accepting the $matches from preg_match
	 *  and returning either a raw or escaped query piece.
	 * @return array[] The sequential set of query pieces $queryPart was
	 *  converted into.
	 */
	public static function replacePartsOfQuery( $queryPart, $regex, $callable ) {
		$destination = array();
		$matches = array();
		$offset = 0;
		while ( preg_match( $regex, $queryPart, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$startOffset = $matches[0][1];
			if ( $startOffset > $offset ) {
				$destination[] = array(
					'raw' => substr( $queryPart, $offset, $startOffset - $offset )
				);
			}

			$callableResult = call_user_func( $callable, $matches );
			if ( $callableResult ) {
				$destination[] = $callableResult;
			}

			$offset = $startOffset + strlen( $matches[0][0] );
		}

		if ( $offset < strlen( $queryPart ) ) {
			$destination[] = array(
				'raw' => substr( $queryPart, $offset ),
			);
		}

		return $destination;
	}

	/**
	 * Extracts syntax matching $regex from $term and applies $callback to
	 * the matching pieces. The callback must return a string indicating what,
	 * if anything, of the matching piece of $query should be retained in
	 * the search string. If the piece is completely removed (most common case)
	 * it will be injected as a prefix into any search suggestions made.
	 *
	 * @param SearchContext $context
	 * @param string $term The current search term
	 * @param string $regex An expression that matches the desired special syntax
	 * @param callable $callback Called on for each piece of $term that matches
	 *  $regex. This function will be provided with $matches from
	 *  preg_replace_callback and must return a string which will replace the
	 *  match in $term.
	 * @return string The search term after extracting special syntax
	 */
	public static function extractSpecialSyntaxFromTerm( SearchContext $context, $term, $regex, $callback ) {
		return preg_replace_callback(
			$regex,
			function ( $matches ) use ( $context, $callback ) {
				$result = $callback( $matches );
				if ( $result === '' ) {
					$context->addSuggestPrefix( $matches[0] );
				}
				return $result;
			},
			$term
		);
	}

	/**
	 * Build fields searched by full text search.
	 *
	 * @param float $weight weight to multiply by all fields
	 * @param string $fieldSuffix suffix to add to field names
	 * @param boolean $allFieldAllowed can we use the all field?  False for
	 *  collecting phrases for the highlighter.
	 * @return string[] array of fields to query
	 */
	public function buildFullTextSearchFields( SearchContext $context, $weight, $fieldSuffix, $allFieldAllowed ) {
		if ( $this->useAllField && $allFieldAllowed ) {
			if ( $fieldSuffix === '.near_match' ) {
				// The near match fields can't shard a root field because field fields need it -
				// thus no suffix all.
				return array( "all_near_match^${weight}" );
			}
			return array( "all${fieldSuffix}^${weight}" );
		}

		$fields = array();
		// Only title and redirect support near_match so skip it for everything else
		$titleWeight = $weight * $this->searchWeights[ 'title' ];
		$redirectWeight = $weight * $this->searchWeights[ 'redirect' ];
		if ( $fieldSuffix === '.near_match' ) {
			$fields[] = "title${fieldSuffix}^${titleWeight}";
			$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
			return $fields;
		}
		$fields[] = "title${fieldSuffix}^${titleWeight}";
		$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
		$categoryWeight = $weight * $this->searchWeights[ 'category' ];
		$headingWeight = $weight * $this->searchWeights[ 'heading' ];
		$openingTextWeight = $weight * $this->searchWeights[ 'opening_text' ];
		$textWeight = $weight * $this->searchWeights[ 'text' ];
		$auxiliaryTextWeight = $weight * $this->searchWeights[ 'auxiliary_text' ];
		$fields[] = "category${fieldSuffix}^${categoryWeight}";
		$fields[] = "heading${fieldSuffix}^${headingWeight}";
		$fields[] = "opening_text${fieldSuffix}^${openingTextWeight}";
		$fields[] = "text${fieldSuffix}^${textWeight}";
		$fields[] = "auxiliary_text${fieldSuffix}^${auxiliaryTextWeight}";
		$namespaces = $context->getNamespaces();
		if ( !$namespaces || in_array( NS_FILE, $namespaces ) ) {
			$fileTextWeight = $weight * $this->searchWeights[ 'file_text' ];
			$fields[] = "file_text${fieldSuffix}^${fileTextWeight}";
		}
		return $fields;
	}

	/**
	 * @param string $term
	 * @param boolean $allFieldAllowed
	 * @return string
	 */
	public function switchSearchToExact( SearchContext $context, $term, $allFieldAllowed ) {
		$exact = join( ' OR ', self::buildFullTextSearchFields( $context, 1, ".plain:$term", $allFieldAllowed ) );
		return "($exact)";
	}

	/**
	 * Expand wildcard queries to the all.plain and title.plain fields if
	 * wgCirrusSearchAllFields[ 'use' ] is set to true. Fallback to all
	 * the possible fields otherwise. This prevents applying and compiling
	 * costly wildcard queries too many times.
	 *
	 * @param string $term
	 * @return string
	 */
	public function switchSearchToExactForWildcards( SearchContext $context, $term ) {
		// Try to limit the expansion of wildcards to all the subfields
		// We still need to add title.plain with a high boost otherwise
		// match in titles be poorly scored (actually it breaks some tests).
		if( $this->useAllField ) {
			$titleWeight = $this->searchWeights['title'];
			$fields = array();
			$fields[] = "title.plain:$term^${titleWeight}";
			$fields[] = "all.plain:$term";
			$exact = join( ' OR ', $fields );
			return "($exact)";
		} else {
			return self::switchSearchToExact( $context, $term, false );
		}
	}
}
