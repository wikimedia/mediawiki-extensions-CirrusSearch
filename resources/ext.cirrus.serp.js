( function ( $, mw ) {
	$( function () {
		if ( !history.replaceState ) {
			return;
		}
		var uri = new mw.Uri( location.href );
		if ( uri.query.searchToken ) {
			delete uri.query.searchToken;
			history.replaceState( {}, '', uri.toString() );
		}

		$( document ).on( 'click', 'a', function () {
			var uri = new mw.Uri( location.href );
			var token = mw.config.get( 'wgCirrusSearchRequestSetToken' );
			if ( token ) {
				uri.query.searchToken = token;
				history.replaceState( {}, '', uri.toString() );
			}
		} );
	} );
}( jQuery, mediaWiki ) );
