<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\Term;

/**
 * Enters redirect mode, in which redirect documents are searchable. Two value-less
 * query-header keywords share the mode: `withredirects:` makes redirect documents searchable
 * alongside primary documents in one interleaved result list, `onlyredirects:` narrows the same
 * mode to the redirect documents alone. No value may be provided along with either keyword, they
 * are simple boolean flags, and they only take effect at the head of the query.
 *
 * Gated at query time on the two-flag CirrusSearchRedirectDocuments['use'/'build'] switch:
 * a disabled feature degrades to a warning plus zero results.
 */
class WithRedirectsFeature extends SimpleKeywordFeature implements LegacyKeywordFeature {

	/** Redirect documents interleaved with primary documents. */
	private const WITH_REDIRECTS = 'withredirects';

	/** Redirect documents only, primary documents filtered out. */
	private const ONLY_REDIRECTS = 'onlyredirects';

	private SearchConfig $config;

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * @return string[] The list of keywords this feature is supposed to match
	 */
	protected function getKeywords() {
		return [ self::WITH_REDIRECTS, self::ONLY_REDIRECTS ];
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
		if ( !$this->config->buildRedirectDocuments() || !$this->config->useRedirectDocuments() ) {
			// Feature disabled on this wiki: fail closed rather than silently running a
			// normal search the editor did not ask for.
			$context->addWarning( 'cirrussearch-feature-not-available', $key );
			$context->setResultsPossible( false );
			return [ null, false ];
		}
		// Redirect scope drops the must_not page_type:redirect filter that hides redirect
		// documents from standard search, letting both document types through.
		$context->setRedirectScope( true );
		// onlyredirects: then puts back the other half of the restriction, keeping the
		// primary documents out.
		$filter = $key === self::ONLY_REDIRECTS
			? new Term( [ 'page_type' => 'redirect' ] )
			: null;
		return [ $filter, false ];
	}
}
