<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Search\RedirectMode;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\Term;

/**
 * Selects the redirect mode: how the query treats redirects. Three value-less query-header
 * keywords share this feature. `withredirects:` makes redirect documents searchable alongside
 * primary documents in one interleaved result list, `onlyredirects:` narrows the same mode to
 * the redirect documents alone, and `noredirects:` keeps redirect documents hidden while also
 * dropping the target's redirect array from the query, so a page whose only match is one of its
 * redirects is not a result. No value may be provided along with any of them, they are simple
 * boolean flags, and they only take effect at the head of the query.
 *
 * The two keywords that make redirect documents searchable are gated at query time on the
 * two-flag CirrusSearchRedirectDocuments['use'/'build'] switch: a disabled feature degrades to a
 * warning plus zero results. `noredirects:` needs no such gate. It only changes which fields are
 * queried, and the redirect array it drops predates redirect documents, so it works on any wiki.
 */
class RedirectModeFeature extends SimpleKeywordFeature implements LegacyKeywordFeature {

	private SearchConfig $config;

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * @return string[] The list of keywords this feature is supposed to match
	 */
	protected function getKeywords() {
		return [
			RedirectMode::WithRedirects->value,
			RedirectMode::OnlyRedirects->value,
			RedirectMode::NoRedirects->value,
		];
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
		$mode = RedirectMode::from( $key );
		if ( $mode->requiresRedirectDocuments()
			&& ( !$this->config->buildRedirectDocuments() || !$this->config->useRedirectDocuments() )
		) {
			// Feature disabled on this wiki: fail closed rather than silently running a
			// normal search the editor did not ask for.
			$context->addWarning( 'cirrussearch-feature-not-available', $key );
			$context->setResultsPossible( false );
			return [ null, false ];
		}
		// The mode drives two things downstream: whether getQuery() keeps the must_not
		// page_type:redirect filter that hides redirect documents, and whether the query
		// may read the target's redirect array (directly, or through the composite fields
		// that carry it).
		$context->setRedirectMode( $mode );
		// onlyredirects: then puts back the other half of the restriction, keeping the
		// primary documents out.
		$filter = $mode->excludesPrimaryDocuments()
			? new Term( [ 'page_type' => 'redirect' ] )
			: null;
		return [ $filter, false ];
	}
}
