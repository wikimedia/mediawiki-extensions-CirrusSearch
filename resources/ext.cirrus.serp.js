$( function () {
	if ( !history.replaceState ) {
		return;
	}

	try {
		var uri = new mw.Uri( location.href );
		if ( uri.query.searchToken ) {
			delete uri.query.searchToken;
			history.replaceState( {}, '', uri.toString() );
		}
	} catch ( e ) {
		// Don't install the click handler when the browser location can't be parsed anyway
		return;
	}

	// No need to install the click handler when there is no token
	if ( !mw.config.get( 'wgCirrusSearchRequestSetToken' ) ) {
		return;
	}

	$( document ).on( 'click', 'a', function () {
		try {
			var clickUri = new mw.Uri( location.href );
			clickUri.query.searchToken = mw.config.get( 'wgCirrusSearchRequestSetToken' );
			history.replaceState( {}, '', clickUri.toString() );
		} catch ( e ) {
		}
	} );
} );
