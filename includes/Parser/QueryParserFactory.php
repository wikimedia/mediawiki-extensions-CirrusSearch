<?php

namespace CirrusSearch\Parser;

use CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser;
use CirrusSearch\Search\Escaper;
use CirrusSearch\SearchConfig;

/**
 * Simple factory to create QueryParser instance based on the host wiki config.
 * @see QueryParser
 */
class QueryParserFactory {

	/**
	 * Get the default fulltext parser.
	 * @param SearchConfig $config the host wiki config
	 * @return QueryParser
	 */
	public static function newFullTextQueryParser( SearchConfig $config ) {
		$escaper = new Escaper( $config->get( 'LanguageCode' ), $config->get( 'CirrusSearchAllowLeadingWildcard' ) );
		$repository = new FTQueryClassifiersRepository( $config );
		return new QueryStringRegexParser( new FullTextKeywordRegistry( $config ),
			$escaper, $config->get( 'CirrusSearchStripQuestionMarks' ), $repository );
	}
}
