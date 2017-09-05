/*jshint esversion: 6,  node:true */

/**
 * Step definitions. Each step definition is bound to the World object,
 * so any methods or properties in World are available here.
 *
 * Not: Do not use the fat-arrow syntax to define step functions, because
 * Cucumber explicity binds the 'this' to 'World'. Arrow function would
 * bind `this` to the parent function instead, which is not what we want.
 */

const defineSupportCode = require('cucumber').defineSupportCode,
SpecialVersion = require('../support/pages/special_version'),
	ArticlePage = require('../support/pages/article_page'),
	expect = require( 'chai' ).expect;

defineSupportCode( function( {Given, When, Then} ) {

	When( /^I go to (.*)$/, function ( title ) {
		this.visit( ArticlePage.title( title ) );
	} );

	When( /^I ask suggestion API for (.*)$/, function ( query ) {
		return this.stepHelpers.suggestionSearch( query );
	} );

	When( /^I ask suggestion API at most (\d+) items? for (.*)$/, function( limit, query ) {
		return this.stepHelpers.suggestionSearch( query, limit );
	} );

	Then( /^there is a software version row for (.+)$/ , function ( name ) {
		expect( SpecialVersion.software_table_row( name ) ).not.to.equal( null );
	} );

	Then( /^the API should produce list containing (.*)/, function( term ) {
		expect( this.apiResponse[ 1 ] ).to.include( term );
	} );

	Then( /^the API should produce empty list/, function() {
		expect( this.apiResponse[ 1 ] ).to.have.length( 0 );
	} );

	Then( /^the API should produce list starting with (.*)/, function( term ) {
		expect( this.apiResponse[ 1 ][ 0 ] ).to.equal( term );
	} );

	Then( /^the API should produce list of length (\d+)/, function( length ) {
		expect( this.apiResponse[ 1 ] ).to.have.length( parseInt( length, 10 ) );
	} );

});
