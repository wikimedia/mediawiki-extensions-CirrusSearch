/*jshint esversion: 6, node:true */

/**
 * Hooks are run before or after Cucumber executes a test scenario.
 * The World object is bound to the hooks as `this`, so any method
 * or property in World is available here.
 */
var {defineSupportCode} = require( 'cucumber' );

// This file is loaded in the initial cucumbe setup,
// so these variables are retained across tests.
var clean = false,
	suggest = false;

defineSupportCode( function( { After, Before } ) {

	Before( {tags: "@clean" }, function () {
		if ( clean ) return true;
		clean = true;
		return this.stepHelpers.deletePage( "DeleteMeRedirect" );
	} );

	Before( {tags: "@api" }, function () {
		return true;
	});

	Before( {tags: "@suggest" }, function () {
		if ( suggest ) return true;
		suggest = true;
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
