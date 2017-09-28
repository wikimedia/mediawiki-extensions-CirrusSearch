/*jshint esversion: 6, node:true */

var {defineSupportCode} = require( 'cucumber' );

defineSupportCode( function( { After, Before } ) {
	let BeforeOnce = function ( options, fn ) {
		Before( options, function () {
			return this.tags.check( options.tags ).then( ( status ) => {
				if ( status === 'new' ) {
					return fn.call ( this ).then( () => this.tags.complete( options.tags ) );
				}
			} );
		} );
	};

	BeforeOnce( { tags: "@clean" }, function () {
		return this.stepHelpers.deletePage( "DeleteMeRedirect" );
	} );

	Before( { tags: "@api" }, function () {
		return true;
	} );

	BeforeOnce( { tags: "@suggest" }, function () {
		let batchJobs = {
			edit: {
				"X-Men": "The X-Men are a fictional team of superheroes",
				"Xavier: Charles": "Professor Charles Francis Xavier (also known as Professor X) is the founder of [[X-Men]]",
				"X-Force": "X-Force is a fictional team of of [[X-Men]]",
				"Magneto": "Magneto is a fictional character appearing in American comic books",
				"Max Eisenhardt": "#REDIRECT [[Magneto]]",
				"Eisenhardt: Max": "#REDIRECT [[Magneto]]",
				"Magnetu": "#REDIRECT [[Magneto]]",
				"Ice": "It's cold.",
				"Iceman": "Iceman (Robert \"Bobby\" Drake) is a fictional superhero appearing in American comic books published by Marvel Comics and is...",
				"Ice Man (Marvel Comics)": "#REDIRECT [[Iceman]]",
				"Ice-Man (comics books)": "#REDIRECT [[Iceman]]",
				"Ultimate Iceman": "#REDIRECT [[Iceman]]",
				"Électricité": "This is electicity in french.",
				"Elektra": "Elektra is a fictional character appearing in American comic books published by Marvel Comics.",
				"Help:Navigation": "When viewing any page on MediaWiki...",
				"V:N": "#REDIRECT [[Help:Navigation]]",
				"Z:Navigation": "#REDIRECT [[Help:Navigation]]",
				"Venom": "Venom: or the Venom Symbiote: is a fictional supervillain appearing in American comic books published by Marvel Comics",
				"Sam Wilson": "Warren Kenneth Worthington III: originally known as Angel and later as Archangel: ... Marvel Comics like [[Venom]]. {{DEFAULTSORTKEY:Wilson: Sam}}",
				"Zam Wilson": "#REDIRECT [[Sam Wilson]]",
				"The Doors": "The Doors were an American rock band formed in 1965 in Los Angeles.",
				"Hyperion Cantos/Endymion": "Endymion is the third science fiction novel by Dan Simmons.",
				"はーい": "makes sure we do not fail to index empty tokens (T156234)"
			}
		};
		return this.apiClient.loginAndEditToken().then( () => {
			return this.apiClient.batch(batchJobs, 'CirrusSearch integration test edit');
		} );
	} );

} );
