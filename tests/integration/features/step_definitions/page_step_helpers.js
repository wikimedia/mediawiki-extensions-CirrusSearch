/*jshint esversion: 6,  node:true */
/**
 * StepHelpers are abstracted functions that usually represent the
 * behaviour of a step. They are placed here, instead of in the actual step,
 * so that they can be used in the Hook functions as well.
 *
 * Cucumber.js considers calling steps explicitly an antipattern,
 * and therefore this ability has not been implemented in Cucumber.js even though
 * it is available in the Ruby implementation.
 * https://github.com/cucumber/cucumber-js/issues/634
 */

const expect = require( 'chai' ).expect,
	fs = require( 'fs' ),
	path = require( 'path' ),
	Promise = require( 'bluebird' ); // jshint ignore:line

class StepHelpers {
	constructor( world, wiki ) {
		this.world = world;
		this.apiPromise = world.onWiki( wiki || world.config.wikis.default );
	}

	onWiki( wiki ) {
		return new StepHelpers( this.world, wiki );
	}

	deletePage( title ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;
			try {
				yield client.delete( title, "CirrusSearch integration test delete" );
				yield this.waitForOperation( 'delete', title );
			} catch ( err ) {
				// still return true if page doesn't exist
				expect( err.message ).to.include( "doesn't exist" );
			}
		} );
	}

	editPage( title, text, append = false ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;

			if ( text[0] === '@' ) {
				text = fs.readFileSync( path.join( __dirname, 'articles', text.substr( 1 ) ) ).toString();
			}
			let fetchedText = yield this.getWikitext( title );
			if ( append ) {
				text = fetchedText + text;
			}
			if ( text.trim() !== fetchedText.trim() ) {
				yield client.edit( title, text );
				yield this.waitForOperation( 'edit', title );
			}
		} ).call( this );
	}

	getWikitext( title ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;
			let response = yield client.request( {
				action: "query",
				format: "json",
				formatversion: 2,
				prop: "revisions",
				rvprop: "content",
				titles: title
			} );
			if ( response.query.pages[0].missing ) {
				return "";
			}
			return response.query.pages[0].revisions[0].content;
		} ).call( this );
	}

	suggestionSearch( query, limit = 'max' ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;

			try {
				let response = yield client.request( {
					action: 'opensearch',
					search: query,
					cirrusUseCompletionSuggester: 'yes',
					limit: limit
				} );
				this.world.setApiResponse( response );
			} catch ( err ) {
				this.world.setApiError( err );
			}
		} ).call( this );
	}

	suggestionsWithProfile( query, profile ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;

			try {
				let response = yield client.request( {
					action: 'opensearch',
					search: query,
					profile: profile
				} );
				this.world.setApiResponse( response );
			} catch ( err ) {
				this.world.setApiError( err );
			}
		} ).call( this );
	}

	searchFor( query, options = {} ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;

			try {
				let response = yield client.request( Object.assign( options, {
					action: "query",
					list: "search",
					srsearch: query,
					srprop: "snippet|titlesnippet|redirectsnippet|sectionsnippet|categorysnippet|isfilematch",
					formatversion: 2
				} ) );
				this.world.setApiResponse( response );
			} catch ( err ) {
				this.world.setApiError( err );
			}
		} ).call( this );
	}

	waitForMs( ms ) {
		return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	}

	waitForOperation( operation, title ) {
		return Promise.coroutine( function* () {
			let expect = operation === 'delete' ? false : true;
			let exists = yield this.checkExists( title );
			while ( expect !== exists ) {
				yield this.waitForMs( 100 );
				exists = yield this.checkExists( title );
			}
		} ).call( this );
	}

	checkExists( title ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;
			let response = yield client.request( {
				action: 'query',
				prop: 'cirrusdoc',
				titles: title,
				format: 'json',
				formatversion: 2
			} );
			if ( response.query.normalized ) {
				for ( let norm of response.query.normalized ) {
					if ( norm.from === title ) {
						title = norm.to;
						break;
					}
				}
			}
			for ( let page of response.query.pages ) {
				if ( page.title === title ) {
					// without boolean cast we could return undefined
					return Boolean( page.cirrusdoc && page.cirrusdoc.length > 0 );
				}
			}
			return false;
		} ).call( this );
	}
}

module.exports = StepHelpers;
