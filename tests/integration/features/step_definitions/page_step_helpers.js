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
'use strict';

const expect = require( 'chai' ).expect,
	fs = require( 'fs' ),
	path = require( 'path' ),
	articlePath = path.dirname( path.dirname( path.dirname( __dirname ) ) ) + '/integration/articles/';

class StepHelpers {
	constructor( world, wiki ) {
		this.world = world;
		this.wiki = wiki || world.config.wikis.default;
		this.apiPromise = world.onWiki( this.wiki );
	}

	onWiki( wiki ) {
		return new StepHelpers( this.world, wiki );
	}

	async deletePage( title, options = {} ) {
		const client = await this.apiPromise;
		try {
			await client.delete( title, 'CirrusSearch integration test delete' );
			if ( !options.skipWaitForOperatoin ) {
				await this.waitForOperation( 'delete', title );
			}
		} catch ( err ) {
			// still return true if page doesn't exist
			expect( err.message ).to.include( "doesn't exist" );
		}
	}

	async uploadFile( title, fileName, description ) {
		const client = await this.apiPromise;
		const filePath = path.join( articlePath, fileName );
		await client.batch( [
			[ 'upload', fileName, filePath, '', { text: description } ]
		] );
		await this.waitForOperation( 'upload', fileName );
	}

	async editPage( title, text, options = {} ) {
		const client = await this.apiPromise;
		const isNullEdit = text === null;

		const fetchedText = await this.getWikitext( title );
		if ( isNullEdit ) {
			text = fetchedText;
		} else if ( text[ 0 ] === '@' ) {
			text = fs.readFileSync( path.join( articlePath, text.slice( 1 ) ) ).toString();
		}
		if ( options.append ) {
			text = fetchedText + text;
		}
		if ( isNullEdit || text.trim() !== fetchedText.trim() ) {
			const editResponse = await client.edit( title, text );
			if ( !options.skipWaitForOperation ) {
				await this.waitForOperation( 'edit', title, null, editResponse.edit.newrevid );
			}
		}
	}

	async getWikitext( title ) {
		const client = await this.apiPromise;
		const response = await client.request( {
			action: 'query',
			format: 'json',
			formatversion: 2,
			prop: 'revisions',
			rvprop: 'content',
			titles: title
		} );
		if ( response.query.pages[ 0 ].missing ) {
			return '';
		}
		return response.query.pages[ 0 ].revisions[ 0 ].content;
	}

	async movePage( from, to, noRedirect = true ) {
		const client = await this.apiPromise;
		const req = {
			action: 'move',
			from: from,
			to: to,
			token: client.editToken,
			formatversion: 2
		};
		if ( noRedirect ) {
			req.noredirect = 1;
		}
		await client.request( req );
		// If no redirect was left behind we have no way to check the
		// old page has been removed from elasticsearch. The page table
		// entry itself was renamed leaving nothing (except a log) for
		// the api to find. Post-processing in cirrus will remove deleted
		// pages that elastic returns though, so perhaps not a big deal
		// (except we cant test it was really deleted...).
		await this.waitForOperation( 'edit', to );
		if ( !noRedirect ) {
			await this.waitForOperation( 'edit', from );
		}
	}

	async suggestionSearch( query, limit = 'max', secondTryProfile = undefined, idx = -1 ) {
		const client = await this.apiPromise;

		const params = {
			action: 'opensearch',
			search: query,
			cirrusUseCompletionSuggester: 'yes',
			limit: limit
		};
		if ( idx >= 0 ) {
			params.cirrusCompletionAltIndexId = idx;
		}
		if ( secondTryProfile ) {
			params.cirrusUseSecondTryProfile = secondTryProfile;
		}

		try {
			const response = await client.request( params );
			this.world.setApiResponse( response );
		} catch ( err ) {
			this.world.setApiError( err );
		}
	}

	async suggestionsWithProfile( query, profile, namespaces = undefined ) {
		const client = await this.apiPromise;
		const request = {
			action: 'opensearch',
			search: query,
			profile: profile
		};
		if ( namespaces ) {
			request.namespace = namespaces.replace( /','/g, '|' );
		}
		try {
			const response = await client.request( request );
			this.world.setApiResponse( response );
		} catch ( err ) {
			this.world.setApiError( err );
		}
	}

	async searchFor( query, options = {} ) {
		const client = await this.apiPromise;

		try {
			const response = await client.request( Object.assign( options, {
				action: 'query',
				list: 'search',
				srsearch: query,
				srprop: 'snippet|titlesnippet|redirectsnippet|sectionsnippet|categorysnippet|isfilematch',
				formatversion: 2
			} ) );
			this.world.setApiResponse( response );
		} catch ( err ) {
			this.world.setApiError( err );
		}
	}

	async wikibaseSearchFor( query, options = {} ) {
		const client = await this.apiPromise;

		try {
			const response = await client.request( Object.assign( options, {
				action: 'query',
				generator: 'search',
				gsrsearch: query,
				prop: 'entityterms',
				format: 'json',
				formatversion: 2
			} ) );
			this.world.setApiResponse( response );
		} catch ( err ) {
			this.world.setApiError( err );
		}
	}

	async waitForDocument( title, check ) {
		const timeoutMs = 20000;
		const start = new Date();
		let lastError;
		while ( true ) {
			const doc = await this.getCirrusIndexedContent( title );
			if ( doc.cirrusdoc && doc.cirrusdoc.length > 0 ) {
				try {
					check( doc.cirrusdoc[ 0 ] );
					break;
				} catch ( err ) {
					lastError = err;
				}
			}
			if ( Date.now() - start >= timeoutMs ) {
				throw lastError || new Error( `Timeout out waiting for ${ title }` );
			}
			await this.waitForMs( 200 );
		}
	}

	waitForMs( ms ) {
		// eslint-disable-next-line no-promise-executor-return
		return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	}

	waitForOperation( operation, title, timeoutMs = null, revisionId = null ) {
		return this.waitForOperations( [ [ operation, title, revisionId ] ], null, timeoutMs );
	}

	/**
	 * Wait by scanning the cirrus indices to check if the list of operations
	 * has been done and are effective in elastic.
	 *
	 * @param {Array[]} operations List of operations to wait for.
	 *  Array elements are [ operation, title, revisionId (optional) ]
	 * @param {Function} log Log callback when an operation is done
	 * @param {number} timeoutMs Max time to wait, default to Xsec*number of operations.
	 *  Where X is 10 for simple operations and 30s for uploads.
	 * @return {Promise} that resolves when everything is done or fails otherwise.
	 */
	async waitForOperations( operations, log = null, timeoutMs = null ) {
		if ( !timeoutMs ) {
			timeoutMs = Math.max(
				20000,
				operations.reduce( ( total, v ) => total + ( v[ 0 ].match( /^upload/ ) ? 30000 : 10000 ), 0 )
			);
		}
		const start = new Date();

		const done = [];
		const failedOps = ( ops, doneOps ) => ops.filter( ( v, idx ) => !doneOps.includes( idx ) ).map( ( v ) => `[${ v[ 0 ] }:${ v[ 1 ] }]` ).join();
		while ( done.length !== operations.length ) {
			let consecutiveFailures = 0;
			for ( let i = 0; i < operations.length; i++ ) {
				const operation = operations[ i ][ 0 ];
				let title = operations[ i ][ 1 ];
				const revisionId = operations[ i ][ 2 ];
				if ( done.includes( i ) ) {
					continue;
				}
				if ( consecutiveFailures > 10 ) {
					// restart the loop when we fail too many times
					// next pages, let's retry from the beginning.
					// mwbot is perhaps behind so instead of continuing to check
					consecutiveFailures = 0;
					break;
				}
				if ( ( operation === 'upload' || operation === 'uploadOverwrite' ) && !title.startsWith( 'File:' ) ) {
					title = 'File:' + title;
				}
				const expectExists = operation !== 'delete';
				const exists = await this.checkExists( title, revisionId );
				if ( exists === expectExists ) {
					if ( log ) {
						log( title, done.length + 1 );
					}
					done.push( i );
					consecutiveFailures = 0;
				} else {
					consecutiveFailures++;
				}
				await this.waitForMs( 10 );
			}
			if ( done.length === operations.length ) {
				break;
			}

			if ( Date.now() - start >= timeoutMs ) {
				const failed_ops = failedOps( operations, done );
				throw new Error( `Timed out waiting for ${ failed_ops }` );
			}
			await this.waitForMs( 50 );
		}
	}

	/**
	 * Call query api with cirrusdoc prop to return the docs identified
	 * by title that are indexed in elasticsearch.
	 *
	 * NOTE: Multiple docs can be returned if the doc identified by title is indexed
	 * over multiple indices (content/general).
	 *
	 * @param {string} title page title
	 * @return {Promise} resolves to an array of indexed docs or null if title not indexed
	 */
	async getCirrusIndexedContent( title ) {
		const client = await this.apiPromise;
		const response = await client.request( {
			action: 'query',
			prop: 'cirrusdoc',
			titles: title,
			format: 'json',
			formatversion: 2
		} );
		if ( response.query.normalized ) {
			for ( const norm of response.query.normalized ) {
				if ( norm.from === title ) {
					title = norm.to;
					break;
				}
			}
		}
		for ( const page of response.query.pages ) {
			if ( page.title === title ) {
				return page;
			}
		}
		return null;
	}

	/**
	 * Check if title is indexed
	 *
	 * @param {string} title
	 * @param {string} revisionId
	 * @return {Promise.<boolean>} resolves to a boolean
	 */
	async checkExists( title, revisionId = null ) {
		const page = await this.getCirrusIndexedContent( title );
		const content = page.cirrusdoc;
		// without boolean cast we could return undefined
		let isOk = Boolean( content && content.length > 0 );
		// Is the requested page and the returned document dont have the same
		// title that means we have a redirect. In that case the revision id
		// wont match, but the backend api ensures the redirect is now contained
		// within the document. Unfortunately if the page was just edited to
		// now be a redirect anymore this is wrong ...
		if ( isOk && revisionId && content[ 0 ].source.title === page.title ) {
			isOk = parseInt( content[ 0 ].source.version, 10 ) === revisionId;
		}
		return isOk;
	}

	async pageIdOf( title ) {
		const client = await this.apiPromise;
		const response = await client.request( { action: 'query', titles: title, formatversion: 2 } );
		return response.query.pages[ 0 ].pageid;
	}
}

module.exports = StepHelpers;
