/**
 * The World is a container for global state shared across test steps.
 * The World is instanciated after every scenario, so state cannot be
 * carried over between scenarios.
 *
 * Note: the `StepHelpers` are bound to the World object so that they have access
 * to the same apiClient instance as `World` (useful because the apiClient
 * keeps a user/login state).
 */

'use strict';

const { Before, setWorldConstructor } = require( '@cucumber/cucumber' ),
	{ getValue: getSharedStoreValue } = require( '@wdio/shared-store-service' ),
	log = require( 'semlog' ).log,
	Bot = require( 'mwbot' ),
	StepHelpers = require( '../step_definitions/page_step_helpers' ),
	Page = require( './pages/page' );

// world gets re-created all the time. Try and save some time logging
// in by sharing api clients
const apiClients = {};

function World( { attach, parameters } ) {
	// default properties
	this.attach = attach;
	this.parameters = parameters;

	// Since you can't pass values between step definitions directly,
	// the last Api response is stored here so it can be accessed between steps.
	// (I have a feeling this is prone to race conditions).
	// By suggestion of this stack overflow question.
	// https://stackoverflow.com/questions/26372724/pass-variables-between-step-definitions-in-cucumber-groovy
	this.apiResponse = undefined;
	this.apiError = undefined;

	this.setApiResponse = function ( value ) {
		this.apiResponse = value;
		this.apiError = undefined;
	};
	this.setApiError = function ( error ) {
		this.apiResponse = undefined;
		this.apiError = error;
	};
	// Per-wiki api clients
	this.onWiki = function ( wiki = this.config.wikis.default ) {
		if ( apiClients[ wiki ] ) {
			return apiClients[ wiki ];
		}

		if ( !this.config.wikis[ wiki ] ) {
			const available = Object.keys( this.config.wikis ).join( ', ' );
			throw new Error( `In "World.onWiki(wiki)" wiki is not found: wiki=${ wiki }; available=${ available }` );
		}

		const w = this.config.wikis[ wiki ];
		const client = new Bot();
		client.setOptions( {
			verbose: true,
			silent: false,
			defaultSummary: 'MWBot',
			concurrency: 1,
			apiUrl: w.apiUrl
		} );

		// Add a generic method to get access to the request that triggered a response, so we
		// can add generic error reporting that includes the requested api url
		const origRawRequest = client.rawRequest;
		client.rawRequest = function ( requestOptions ) {
			return origRawRequest.call( client, requestOptions ).then( ( response ) => {
				// TODO: What conditions cause this to be a string?
				if ( typeof response !== 'string' ) {
					response.__request = requestOptions;
				}
				return response;
			} );
		};

		apiClients[ wiki ] = client.loginGetEditToken( {
			username: w.username,
			password: w.botPassword,
			apiUrl: w.apiUrl
		} ).then( () => client );

		// Catch anything trying to re-login and break everything
		client.loginGetEditToken = undefined;

		return apiClients[ wiki ];
	};

	// Shortcut for browser.url(), accepts a Page object
	// as well as a string, assumes the Page object
	// has a url property
	this.visit = async function ( page, wiki = this.config.wikis.default ) {
		let params;
		if ( page instanceof Page && page.url_params ) {
			params = page.url_params;
		}
		if ( typeof page === 'string' && page ) {
			params = 'title=' + page;
		}
		if ( !params ) {
			throw new Error( `In "World.visit(page)" page is falsy: page=${ page }` );
		}
		const tmpUrl = this.config.wikis[ wiki ].baseUrl + '?' + params;
		const silentLog = this.config.logLevel !== 'verbose';
		log( `[D] Visiting page: ${ tmpUrl }`, silentLog );
		await browser.url( tmpUrl );
		// logs full URL in case of typos, misplaced backslashes.
		log( `[D] Visited page: ${ browser.getUrl() }`, silentLog );
	};
}

// Can't do anything async in constructor, anything config dependent happens here.
Before( async function () {
	// appOptions from wdio.conf.js
	this.config = await getSharedStoreValue( 'appOptions' );

	// Binding step helpers to this World.
	// Step helpers are just step functions that are abstracted
	// for the purpose of using them outside of the steps themselves.
	this.stepHelpers = new StepHelpers( this );

	// We depend on the browser being large enough that the search input isn't hidden
	await browser.setWindowSize( 1920, 1080 );
} );

setWorldConstructor( World );
