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

const expect = require( 'chai' ).expect;

class StepHelpers {
	constructor( world ) {
		this.world = world;
	}

	deletePage( title ) {
		return this.world.apiClient.loginAndEditToken().then( () => {
			return this.world.apiClient.delete( title, "CirrusSearch integration test delete" )
				.catch( ( err ) => {
					// still return true if page doesn't exist
					return expect( err.message ).to.include( "doesn't exist" );
				} );
		} );
	}
	editPage( title, content ) {
		return this.world.apiClient.loginAndEditToken().then( () => {
			return this.world.apiClient.edit( title, content, "CirrusSearch integration test edit" );
		} );
	}

	suggestionSearch( query, limit = 'max' ) {
		return this.world.apiClient.request( {
			action: 'opensearch',
			search: query,
			cirrusUseCompletionSuggester: 'yes',
			limit: limit
		} ).then( ( response ) => this.world.setApiResponse( response ) );
	}

}

module.exports = StepHelpers;