<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;

/**
 * Enters redirect mode: a value-less, query-header keyword that makes redirect documents
 * searchable alongside primary documents in one interleaved result list. No value may be
 * provided along with this keyword, it is a simple boolean flag, and it only takes effect at
 * the head of the query.
 *
 * Gated at query time on the two-flag CirrusSearchRedirectDocuments['use'/'build'] switch:
 * a disabled feature degrades to a warning plus zero results.
 */
class WithRedirectsFeature extends SimpleKeywordFeature implements LegacyKeywordFeature {

	private SearchConfig $config;

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * @return string[] The list of keywords this feature is supposed to match
	 */
	protected function getKeywords() {
		return [ 'withredirects' ];
	}

	/**
	 * @return bool
	 */
	public function hasValue() {
		return false;
	}

	/**
	 * @return bool
	 */
	public function queryHeader() {
		return true;
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		// Our use case for redirect documents is limited to the host wiki
		return CrossSearchStrategy::hostWikiOnlyStrategy();
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
		if ( $this->rejectNegation( $context, $key, $negated ) ) {
			return [ null, false ];
		}
		if ( $this->config->buildRedirectDocuments() && $this->config->useRedirectDocuments() ) {
			$context->setRedirectScope( true );
		} else {
			// Feature disabled on this wiki: fail closed rather than silently running a
			// normal search the editor did not ask for.
			$context->addWarning( 'cirrussearch-feature-withredirects-not-enabled' );
			$context->setResultsPossible( false );
		}
		return [ null, false ];
	}
}
