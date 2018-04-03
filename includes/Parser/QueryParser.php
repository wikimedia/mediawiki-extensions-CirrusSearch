<?php

namespace CirrusSearch\Parser;

use CirrusSearch\Parser\AST\ParsedQuery;

/**
 * Query parser.
 *
 * Parse a user query (usually fulltext query) into a ParsedQuery
 */
interface QueryParser {

	/**
	 * Parse a user query.
	 * @param string $query
	 * @return ParsedQuery
	 */
	public function parse( $query );
}
