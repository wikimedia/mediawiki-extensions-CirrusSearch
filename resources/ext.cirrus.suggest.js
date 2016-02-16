( function ( $, mw ) {
	$( function () {
		// Override default opensearch
		mw.searchSuggest.request = function ( api, query, response, maxRows ) {
			return api.get( {
				action: 'cirrus-suggest',
				text: query,
				limit: maxRows
			} ).done( function ( data ) {
				var results = $.map( data.suggest, function ( suggestion ) {
					return suggestion.text;
				} );
				response( results, {
					type: "comp_suggest",
					query: query
				} );
			} );
		};
	} );
}( jQuery, mediaWiki ) );
