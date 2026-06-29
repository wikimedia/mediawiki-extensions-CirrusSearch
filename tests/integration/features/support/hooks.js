'use strict';

const { Before } = require( '@cucumber/cucumber' );
const MWBot = require( 'mwbot' );

// Most page/file setup formerly defined here has been migrated to a static
// corpus at tests/integration/corpus/corpus.yaml, pre-loaded by the
// CirrusSearch:ImportTestCorpus maintenance script (see that directory's
// README). Only setup that is not plain corpus content remains below:
//  - @clean: a delete, not a page to create.
//  - @wbcs: a Wikibase entity created via the wbeditentity API.
// NOTE: the completion-suggester index build that used to live in the @suggest
// hook (action=cirrus-suggest-index) is no longer here; the test environment's
// prep phase must build it (e.g. CirrusSearch:UpdateSuggesterIndex) after
// indexing the corpus, alongside ForceSearchIndex.

const BeforeOnce = function ( options, fn ) {
	Before( options, async function () {
		if ( this.config.preloaded === 'yes' ) {
			return;
		}
		const response = await this.tags.check( options.tags );
		if ( response === 'complete' ) {
			return;
		} else if ( response === 'new' ) {
			try {
				await fn.call( this );
			} catch ( err ) {
				console.log( `Failed initializing tag ${ options.tags }: `, err );
				await this.tags.reject( options.tags );
				return;
			}
			await this.tags.complete( options.tags );
		} else if ( response === 'reject' ) {
			throw new Error( 'Tag failed to initialize previously' );
		} else {
			throw new Error( 'Unknown tag check response: ' + response );
		}
	} );
};

const waitForBatch = async function ( wiki, batchJobs ) {
	const stepHelpers = this.stepHelpers.onWiki( wiki );
	const queue = [];
	if ( Array.isArray( batchJobs ) ) {
		for ( const jobDef of batchJobs ) {
			queue.push( [ jobDef[ 0 ], jobDef[ 1 ] ] );
		}
	} else {
		for ( const operation in batchJobs ) {
			const operationJobs = batchJobs[ operation ];
			if ( Array.isArray( operationJobs ) ) {
				for ( const title of operationJobs ) {
					queue.push( [ operation, title ] );
				}
			} else {
				for ( const title in operationJobs ) {
					queue.push( [ operation, title ] );
				}
			}
		}
	}

	await stepHelpers.waitForOperations( queue, ( title, i ) => MWBot.logStatus( '[=] ', i, queue.length, 'incirrus', title ) );
};

const flattenJobs = ( batchJobs ) => {
	if ( !Array.isArray( batchJobs ) ) {
		const flatJobs = [];
		for ( const op in batchJobs ) {
			const data = batchJobs[ op ];
			const jobData = [ op ];
			if ( Array.isArray( data ) ) {
				for ( const title of data ) {
					flatJobs.push( jobData.concat( Array.isArray( title ) ? title : [ title ] ) );
				}
			} else {
				for ( const title in data ) {
					const d = data[ title ];
					flatJobs.push( jobData.concat( [ title ] )
						.concat( Array.isArray( d ) ? d : [ d ] ) );
				}
			}
		}
		return flatJobs;
	}
	return batchJobs;
};

// Run both in parallel so we don't have to wait for the batch to finish
// to start checking things. Hopefully they run in the same order...
const runBatch = async function ( world, wiki, batchJobs ) {
	wiki = wiki || world.config.wikis.default;
	const client = await world.onWiki( wiki );
	batchJobs = flattenJobs( batchJobs );
	// separate redirect edits from the rest and process after, this might give better chances of having this redirect
	// indexed.
	const nonRedirJobs = [];
	const redirJobs = [];
	for ( const singleJob of batchJobs ) {
		if ( singleJob.length >= 3 && singleJob[ 2 ].startsWith( '#REDIRECT ' ) ) {
			redirJobs.push( singleJob );
		} else {
			nonRedirJobs.push( singleJob );
		}
	}

	// TODO: If the batch operation fails the waitForBatch will never complete,
	// it will just stick around forever ...
	await Promise.all( [
		client.batch( nonRedirJobs, 'CirrusSearch integration test edit', 2 ),
		waitForBatch.call( world, wiki, nonRedirJobs )
	] );
	// XXX: try to batch redirects only if they're targeting a different page
	let currentBatch = [];
	for ( const redirJob of redirJobs ) {
		let currentTargets = [];
		if ( currentTargets.includes( redirJob[ 2 ] ) ) {
			await Promise.all( [
				client.batch( currentBatch, 'CirrusSearch integration test edit', 2 ),
				waitForBatch.call( world, wiki, currentBatch )
			] );
			currentTargets = [];
			currentBatch = [];
		}
		currentTargets.push( redirJob[ 2 ] );
		currentBatch.push( redirJob );
	}
	if ( currentBatch.length > 0 ) {
		await Promise.all( [
			client.batch( currentBatch, 'CirrusSearch integration test edit', 2 ),
			waitForBatch.call( world, wiki, currentBatch )
		] );
	}
};

const runBatchFn = ( wiki, batchJobs ) => async function () {
	if ( batchJobs === undefined ) {
		batchJobs = wiki;
		wiki = this.config.wikis.default;
	}
	await runBatch( this, wiki, batchJobs );
};

BeforeOnce( { tags: '@clean' }, runBatchFn( {
	delete: [ 'DeleteMeRedirect' ]
} ) );

BeforeOnce( { tags: '@wbcs' }, async function () {
	// This could all be generalized, but for now we need a single entity
	// to exist that we can search for.
	const wiki = 'wikidata';
	const client = await this.onWiki( wiki );
	const response = await client.request( {
		action: 'wbeditentity',
		new: 'item',
		data: JSON.stringify( {
			labels: {
				en: {
					language: 'en',
					value: 'Universe'
				}
			}
		} ),
		token: client.editToken
	} );
	const title = 'Item:' + response.entity.id;
	const revId = response.entity.lastrevid;
	await this.stepHelpers.onWiki( wiki ).waitForOperation( 'edit', title, null, revId );
} );
