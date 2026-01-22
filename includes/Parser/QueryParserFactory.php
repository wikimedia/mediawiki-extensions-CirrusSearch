<?php

namespace CirrusSearch\Parser;

use CirrusSearch\CachedSparqlClient;
use CirrusSearch\CirrusSearchHookRunner;
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
	 * @param NamespacePrefixParser $namespacePrefix
	 * @param CirrusSearchHookRunner $cirrusSearchHookRunner
	 * @param CachedSparqlClient|null $sparql
	 * @return QueryParser
	 * @throws ParsedQueryClassifierException
	 */
	public static function newFullTextQueryParser(
		SearchConfig $config,
		NamespacePrefixParser $namespacePrefix,
		CirrusSearchHookRunner $cirrusSearchHookRunner,
		?CachedSparqlClient $sparql = null
	) {
		$escaper = new Escaper( $config->get( 'LanguageCode' ), $config->get( 'CirrusSearchAllowLeadingWildcard' ) );
		$repository = new FTQueryClassifiersRepository( $config, $cirrusSearchHookRunner );
		return new QueryStringRegexParser( new FullTextKeywordRegistry( $config, $cirrusSearchHookRunner, $namespacePrefix, $sparql ),
			$escaper, $config->get( 'CirrusSearchStripQuestionMarks' ), $repository, $namespacePrefix,
			$config->get( "CirrusSearchMaxFullTextQueryLength" ) );
	}

}
