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
	Promise = require( 'bluebird' ), // jshint ignore:line
	articlePath = path.dirname(path.dirname(path.dirname(__dirname))) + '/browser/articles/';

class StepHelpers {
	constructor( world, wiki ) {
		this.world = world;
		this.wiki = wiki || world.config.wikis.default;
		this.apiPromise = world.onWiki( this.wiki );
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

	uploadFile( title, fileName, description ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;
			let filePath = path.join( articlePath, fileName );
			yield client.batch( [
				[ 'upload', fileName, filePath, '', { text: description } ]
			] );
			yield this.waitForOperation( 'upload', fileName );
		} ).call( this );
	}

	editPage( title, text, append = false ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;

			if ( text[0] === '@' ) {
				text = fs.readFileSync( path.join( articlePath, text.substr( 1 ) ) ).toString();
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

	suggestionsWithProfile( query, profile, namespaces = undefined ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;
			let request = {
				action: 'opensearch',
				search: query,
				profile: profile,
			};
			if ( namespaces ) {
				request.namespace = namespaces.replace( /','/g, '|' );
			}
			try {
				let response = yield client.request( request );
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

	waitForOperation( operation, title, timeoutMs = 60000 ) {
		return Promise.coroutine( function* () {
			let start = new Date();
			if ( ( operation === 'upload' || operation === 'uploadOverwrite' ) && title.substr( 0, 5 ) !== 'File:' ) {
				title = 'File:' + title;
			}
			let expect = operation === 'delete' ? false : true;
			let exists = yield this.checkExists( title );
			while ( expect !== exists ) {
				if ( new Date() - start >= timeoutMs ) {
					throw new Error( `Timed out waiting for ${operation} on ${this.wiki} ${title}` );
				}
				yield this.waitForMs( 100 );
				exists = yield this.checkExists( title );
			}
		} ).call( this );
	}

	/**
	 * Call query api with cirrusdoc prop to return the docs identified
	 * by title that are indexed in elasticsearch.
	 *
	 * NOTE: Multiple docs can be returned if the doc identified by title is indexed
	 * over multiple indices (content/general).
	 *
	 * @param {string} title page title
	 * @returns {Promise.<[]>} resolves to an array of indexed docs or null if title not indexed
	 */
	getCirrusIndexedContent( title ) {
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
					return page.cirrusdoc;
				}
			}
			return null;
		} ).call( this );
	}

	/**
	 * Check if title is indexed
	 * @param {string} title
	 * @returns {Promise.<boolean>} resolves to a boolean
	 */
	checkExists( title ) {
		return Promise.coroutine( function* () {
			let content = yield this.getCirrusIndexedContent( title );
			// without boolean cast we could return undefined
			return Boolean(content && content.length > 0);
		} ).call( this );
	}

	pageIdOf( title ) {
		return Promise.coroutine( function* () {
			let client = yield this.apiPromise;
			let response = yield client.request( { action: "query", titles: title, formatversion: 2 } );
			return response.query.pages[0].pageid;
		} ).call( this );
	}
}

module.exports = StepHelpers;
