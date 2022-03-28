$( function () {
	var router = require( 'mediawiki.router' );
	if ( !router.isSupported() ) {
		return;
	}

	// Always clean up the address bar, even (and especially) if the feature is disabled.
	try {
		var uri = new mw.Uri( location.href );
		if ( uri.query.searchToken ) {
			delete uri.query.searchToken;
			router.navigateTo( document.title, {
				path: uri.toString(),
				useReplaceState: true
			} );
		}
	} catch ( e ) {
		// Don't install the click handler if the location can't be parsed
		return;
	}

	// Don't install the click handler if the feature isn't enabled
	if ( !mw.config.get( 'wgCirrusSearchRequestSetToken' ) ) {
		return;
	}

	/**
	 * Called when an anchor link in the content area is clicked.
	 *
	 * Note:
	 *
	 * - This is not limited to links that navigate away, e.g. hash links,
	 *   or links within interactive widgets created by JS.
	 *   This means in some edge cases we'll un-pretty the address bar when the
	 *   user is still on the SERP. This is sub-optimal but accepted given
	 *   how rare it is for interface messages or gadgets to add such links
	 *   to a SERP.
	 *
	 * - This is not guruanteed to be called only once for a given link (e.g.
	 *   hook may fire again for a subset or superset of previously-rendered
	 *   content). Again, unlikely on Special:Search, but okay as the below
	 *   is safe to run multiple times.
	 */
	function handlePossiblyNavigatingClick() {
		try {
			var clickUri = new mw.Uri( location.href );
			clickUri.query.searchToken = mw.config.get( 'wgCirrusSearchRequestSetToken' );
			router.navigateTo( document.title, {
				path: clickUri.toString(),
				useReplaceState: true
			} );
		} catch ( e ) {
		}
	}

	// Bind to content area (instead of document.body) to avoid most potential
	// issues and overhead from clicks that are definitely not to a search result.
	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		// Optimisation: Avoid overhead from finding, iterating, and creating event bindings
		// for every result. Use a delegate selector instead.
		$content.on( 'click', 'a', handlePossiblyNavigatingClick );
	} );
} );
