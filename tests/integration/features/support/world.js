/*jshint esversion: 6, node:true */
/*global browser, console */
/**
 * The World is a container for global state shared across test steps.
 * The World is instanciated after every scenario, so state cannot be
 * carried over between scenarios.
 *
 * Note: the `StepHelpers` are bound to the World object so that they have access
 * to the same apiClient instance as `World` (useful because the apiClient
 * keeps a user/login state).
 */
const {defineSupportCode} = require( 'cucumber' ),
	net = require( 'net' ),
	Bot = require( 'mwbot' ),
	StepHelpers = require( '../step_definitions/page_step_helpers' ),
	Page = require( './pages/page' );

// Client for the Server implemented in lib/tracker.js. The server
// tracks what tags have already been initialized so we don't have
// to do it for every feature file.
class TagClient {
	constructor( path ) {
		this.tags = {};
		this.connection = new net.Socket();
		this.connection.connect( path );
		this.nextRequestId = 0;
		this.pendingResponses = {};
		this.connection.on( 'data', ( data ) => {
			let parsed = JSON.parse( data );
			console.log( `received response for request ${parsed.requestId}` );
			if ( parsed && this.pendingResponses[parsed.requestId] ) {
				this.pendingResponses[parsed.requestId]( parsed );
				delete this.pendingResponses[parsed.requestId];
			}
		} );
	}

	request( req ) {
		req.requestId = this.nextRequestId++;
		return new Promise( ( resolve ) => {
			let data = JSON.stringify( req );
			console.log( `Issuing request: ${data}` );
			this.pendingResponses[req.requestId] = resolve;
			this.connection.write( data );
		} );
	}

	check( tag ) {
		if ( this.tags[tag] ) {
			return Promise.resolve( 'complete' );
		}
		return this.request( {
			check: tag
		} ).then( ( response ) => {
			this.tags[tag] = true;
			return response.status;
		} );
	}

	complete( tag ) {
		return this.request( {
			complete: tag
		} );
	}
}

let tagClient = new TagClient( browser.options.trackerPath );

function World( { attach, parameters } ) {
	// default properties
	this.attach = attach;
	this.parameters = parameters;

	// Binding step helpers to this World.
	// Step helpers are just step functions that are abstracted
	// for the purpose of using them outside of the steps themselves (like in hooks).
	this.stepHelpers = new StepHelpers( this );

	// Since you can't pass values between step definitions directly,
	// the last Api response is stored here so it can be accessed between steps.
	// (I have a feeling this is prone to race conditions).
	// By suggestion of this stack overflow question.
	// https://stackoverflow.com/questions/26372724/pass-variables-between-step-definitions-in-cucumber-groovy
	this.apiResponse= "";

	this.setApiResponse = function( value ) {
		this.apiResponse = value;
	};

	// Shortcut to environment configs
	this.config = browser.options;

	// Extra process tracking what tags have been initialized
	this.tags = tagClient;

	this.onWiki = function( wiki = this.config.wikis.default ) {
		let w = this.config.wikis[ wiki ];
		return {
			username: w.username,
			password: w.password,
			apiUrl: w.apiUrl
		};
	 };

	// Instanciates new `mwbot` Api Client
	this.apiClient = new Bot();
	this.apiClient.setOptions({
		verbose: true,
		silent: false,
		defaultSummary: 'MWBot',
		concurrency: 1,
		apiUrl: this.onWiki().apiUrl
	 });

	/**
	 * Shortcut to `loginGetEditToken` that sets a default parameter for login.
	 * Hopefully `mwbot` can handle multiple loggins at once (not yet tested).
	 */
	this.apiClient.loginAndEditToken = ( wiki = this.config.wikis.default ) => {
		let w = this.onWiki( wiki );
		return this.apiClient.loginGetEditToken( w );
	};

	// Shortcut for browser.url(), accepts a Page object
	// as well as a string, assumes the Page object
	// has a url property
	this.visit = function( page ) {
		var tmpUrl;
		if ( page instanceof Page && page.url ) {
			tmpUrl = page.url;
		}
		if ( page instanceof String && page ) {
			tmpUrl = page;
		}
		if ( !tmpUrl ) {
			throw Error( `In "World.visit(page)" page is falsy: page=${ page }` );
		}
		console.log( `Visiting page: ${tmpUrl}` );
		browser.url( tmpUrl );
		// logs full URL in case of typos, misplaced backslashes.
		console.log( `Visited page: ${browser.getUrl()}` );

	};
}

defineSupportCode( function( { setWorldConstructor } ) {
	setWorldConstructor( World );
});
