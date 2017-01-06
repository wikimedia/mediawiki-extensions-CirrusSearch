( function ( $, mw ) {
	$( function () {
		var token = mw.config.get( 'wgCirrusSearchRequestSetToken' ),
			uri = new mw.Uri( location.href );
		if ( history.replaceState && token ) {
			uri.query.searchToken = token;
			history.replaceState( {}, '', uri.toString() );
		}
	} );
}( jQuery, mediaWiki ) );
