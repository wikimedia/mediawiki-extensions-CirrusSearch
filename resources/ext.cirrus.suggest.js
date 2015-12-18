( function ( $, mw ) {
	$( function() {
		// Override default opensearch
		mw.searchSuggest.type = 'cirrus-suggest';
		mw.searchSuggest.request = function ( api, query, response, maxRows ) {
			return api.get( {
				action: 'cirrus-suggest',
				text: query,
				limit: maxRows
			} ).done( function ( data ) {
				response( $.map( data.suggest, function ( suggestion ) {
					return suggestion.text;
				} ) );
			} );
		};
	} );
}( jQuery, mediaWiki ) );
