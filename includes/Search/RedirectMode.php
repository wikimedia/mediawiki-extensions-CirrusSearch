<?php

namespace CirrusSearch\Search;

/**
 * How a query treats redirects.
 *
 * Two independent questions decide the mode. First, which page_type of document is searchable:
 * primary documents only, or redirect documents too. Second, whether the target's redirect array
 * takes part in matching, ranking and highlighting. Only the default mode answers yes to the
 * second question, because a matching redirect is otherwise either its own result or something
 * the user asked to ignore.
 *
 * The case values are the keyword strings, so RedirectModeFeature maps a typed keyword to a mode
 * with a single from().
 *
 * @license GPL-2.0-or-later
 */
enum RedirectMode: string {
	/** Primary documents only, and their redirect array takes part. Applies when no keyword is used. */
	case Standard = 'standard';

	/** Primary documents only, and their redirect array is ignored. */
	case NoRedirects = 'noredirects';

	/** Redirect documents interleaved with primary documents. */
	case WithRedirects = 'withredirects';

	/** Redirect documents alone, primary documents filtered out. */
	case OnlyRedirects = 'onlyredirects';

	/** Index-field prefix that marks a field built from the target's redirect array. */
	private const REDIRECT_FIELD_PREFIX = 'redirect.';

	/**
	 * @return bool Whether redirect documents are searchable. When false the query gets the
	 *  must_not page_type:redirect filter that hides them from standard search.
	 */
	public function searchesRedirectDocuments(): bool {
		return $this === self::WithRedirects || $this === self::OnlyRedirects;
	}

	/**
	 * @return bool Whether primary documents are filtered out, leaving the redirect documents alone.
	 */
	public function excludesPrimaryDocuments(): bool {
		return $this === self::OnlyRedirects;
	}

	/**
	 * @return bool Whether the target's redirect array takes part in the query. When false the
	 *  redirect.* fields are dropped from every field list, and the composite fields that carry
	 *  redirect content (all, all.plain, all_near_match) are replaced by the fields they are
	 *  built from.
	 */
	public function queriesRedirectArray(): bool {
		return $this === self::Standard;
	}

	/**
	 * @return bool Whether the mode needs the wiki to build and use redirect documents. Modes
	 *  that only change which fields are queried work on any wiki.
	 */
	public function requiresRedirectDocuments(): bool {
		return $this->searchesRedirectDocuments();
	}

	/**
	 * @param string $field An index field name, with or without a sub-field suffix.
	 * @return bool Whether the field may be queried in this mode.
	 */
	public function allowsField( string $field ): bool {
		return $this->queriesRedirectArray()
			|| !str_starts_with( $field, self::REDIRECT_FIELD_PREFIX );
	}
}
