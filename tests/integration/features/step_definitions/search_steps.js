/*jshint esversion: 6,  node:true */

const defineSupportCode = require('cucumber').defineSupportCode,
	SearchResultsPage = require('../support/pages/search_results_page'),
	expect = require( 'chai' ).expect;

defineSupportCode( function( {Then,When} ) {
	When( /^I go search for (.*)$/, function ( title ) {
		return this.visit( SearchResultsPage.search( title ) );
	} );

	Then( /^there are no search results/, function() {
		return expect(SearchResultsPage.has_search_results(), 'there are no search results').is.false;
	} );
});
