'use strict';

const { Before } = require( '@cucumber/cucumber' );

// Most page/file setup formerly defined here has been migrated to a static
// corpus at tests/integration/corpus/corpus.yaml, pre-loaded by the
// CirrusSearch:ImportTestCorpus maintenance script (see that directory's
// README). Only setup that is not plain corpus content remains below:
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
