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
	expect = require( 'chai' ).expect,
	querystring = require( 'querystring' ),
	Promise = require( 'bluebird' ); // jshint ignore:line

// Attach extra information to assertion errors about what api call triggered the problem
function withApi( world, fn ) {
	try {
		return fn.call( world );
	} catch ( e ) {
		let request = world.apiResponse ? world.apiResponse.__request : world.apiError.request;
		if ( request ) {
			let qs = Object.assign( {}, request.qs, request.form ),
			    href = request.uri + '?' + querystring.stringify( qs );

			e.message += `\nLast Api: ${href}\nExtra: ` + JSON.stringify( world.apiResponse || world.apiError );
		} else {
			e.message += '\nLast Api: UNKNOWN';
		}
		if ( world.apiError ) {
			e.message += `\nError reported: ${JSON.stringify(world.apiError)}`;
		}
		throw e;
	}
}
defineSupportCode( function( {Given, When, Then} ) {

	When( /^I go to (.*)$/, function ( title ) {
		return this.visit( ArticlePage.title( title ) );
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
		return withApi( this, () => {
			expect( this.apiResponse[ 1 ] ).to.include( term );
		} );
	} );

	Then( /^the API should produce empty list/, function() {
		return withApi( this, () => {
			expect( this.apiResponse[ 1 ] ).to.have.length( 0 );
		} );
	} );

	Then( /^the API should produce list starting with (.*)/, function( term ) {
		return withApi( this, () => {
			expect( this.apiResponse[ 1 ][ 0 ] ).to.equal( term );
		} );
	} );

	Then( /^the API should produce list of length (\d+)/, function( length ) {
		return withApi( this, () => {
			expect( this.apiResponse[ 1 ] ).to.have.length( parseInt( length, 10 ) );
		} );
	} );

	When( /^the api returns error code (.*)$/, function ( code ) {
		return withApi( this, () => {
			expect( this.apiError ).to.include( {
				code: code
			} );
		} );
	} );

	When( /^I get api suggestions for (.*?)(?: using the (.*) profile)?$/, function( search, profile ) {
		// TODO: Add step helper
		return this.stepHelpers.suggestionsWithProfile( search, profile || "fuzzy" );
	} );

	Then( /^(.+) is the (.+) api suggestion$/, function ( title, position ) {
		return withApi( this, () => {
			let pos = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eigth', 'ninth', 'tenth'].indexOf( position );
			if ( title === "none" ) {
				if ( this.apiError && pos === 1 ) {
					// TODO: Why 1? maybe 0?
					return;
				} else {
					expect( this.apiResponse[1] ).to.have.lengthOf.at.most( pos );
				}
			} else {
				expect( this.apiResponse[1] ).to.have.lengthOf.at.least( pos );
				expect( this.apiResponse[1][pos] ).to.equal( title );
			}
		} );
	} );

	Then( /^(.+) is( not)? in the api suggestions$/, function ( title, should_not ) {
		return withApi( this, () => {
			if ( should_not ) {
				expect( this.apiResponse[1] ).to.not.include( title );
			} else {
				expect( this.apiResponse[1] ).to.include( title );
			}
		} );
	} );

	Then( /^the api should offer to search for pages containing (.+)$/, function( term ) {
		return withApi( this, () => {
			expect( this.apiResponse[0] ).to.equal( term );
		} );
	} );

	When( /^a page named (.+) exists(?: with contents (.+))?$/, function ( title, text ) {
		return this.stepHelpers.editPage( title, text || title, false );
	} );

	Then( /^I get api near matches for (.+)$/, function ( search ) {
		return this.stepHelpers.searchFor( search, { srwhat: "nearmatch" } );
	} );

	function checkApiSearchResultStep( title, in_ok, indexes ) {
		indexes = indexes.split( ' or ' ).map( ( index ) => {
			return 'first second third fourth fifth sixth seventh eighth ninth tenth'.split( ' ' ).indexOf( index );
		} );
		if ( title === "none" ) {
			expect( this.apiResponse.query.search ).to.have.lengthOf.below( 1 + Math.min.apply( null, indexes ) );
		} else {
			let found = indexes.map( pos => {
				if ( this.apiResponse.query.search[pos] ) {
					return this.apiResponse.query.search[pos].title;
				} else {
					return null;
				}
			} );
			if ( in_ok ) {
				// What exactly does this do?
				// expect(found).to include(include(title))
				throw new Error( 'Not Implemented' );
			} else {
				expect( found ).to.include(title);
			}
		}
	}
	Then( /^(.+) is( in)? the ((?:[^ ])+(?: or (?:[^ ])+)*) api search result$/, function ( title, in_ok, indexes ) {
		return withApi( this, () => {
			return checkApiSearchResultStep.call( this, title, in_ok, indexes );
		} );
	} );

	When( /^I api search( with rewrites enabled)?(?: with query independent profile ([^ ]+))?(?: with offset (\d+))?(?: in the (.*) language)?(?: in namespaces? (\d+(?: \d+)*))? for (.*)$/, function ( enableRewrites, qiprofile, offset, lang, namespaces, search ) {
		let options = {
			srnamespace: (namespaces || "0").split(' ').join(','),
			srenablerewrites: enableRewrites ? 1 : 0,
		};
		if ( offset ) {
			options.sroffset = offset;
		}
		if ( lang ) {
			options.uselang = lang;
		}
		if ( qiprofile ) {
			options.srqiprofile = qiprofile;
		}
		// This is reset between scenarios
		if ( this.didyoumeanOptions ) {
			Object.assign(options, this.didyoumeanOptions );
		}

		// Generic string replacement of patterns stored in this.searchVars
		search = Object.keys(this.searchVars).reduce( ( str, pattern ) => str.replace( pattern, this.searchVars[pattern] ), search );
		// Replace %{\uXXXX}% with the appropriate unicode code point
		search = search.replace(/%\{\\i([\dA-Fa-f]{4,6})\}%/, ( match, codepoint ) => JSON.parse( `"\\u${codepoint}"` ) );

		return this.stepHelpers.searchFor( search, options );
	} );

	Then( /there are no errors reported by the api/, function () {
		return withApi( this, () => {
			expect( this.apiError ).to.be.undefined; // jshint ignore:line
		} );
	} );

	Then( /there is an api search result/, function () {
		return withApi( this, () => {
			expect( this.apiResponse.query.search ).to.not.have.lengthOf( 0 );
		} );
	} );

	Then( /there are no api search results/, function () {
		return withApi( this, () => {
			expect( this.apiResponse.query.search ).to.have.lengthOf( 0 );
		} );
	} );

	Then( /^there are (\d+) api search results$/, function ( num_results ) {
		return withApi( this, () => {
			expect( this.apiResponse.query.search ).to.have.lengthOf( parseInt( num_results, 10 ) );
		} );
	} );

	Then( /^(.+) is( not)? in the api search results$/, function( title, not ) {
		return withApi( this, () => {
			let titles = this.apiResponse.query.search.map( res => res.title );
			if ( not ) {
				expect( titles ).to.not.include( title );
			} else {
				expect( titles ).to.include( title );
			}
		} );
	} );

	Then( /^this error is reported by api: (.+)$/, function ( expected_error ) {
		return withApi( this, () => {
			expect( this.apiError.info ).to.equal( expected_error.trim() );
		} );
	} );

	When( /^I reset did you mean suggester options$/, function () {
		delete this.didyoumeanOptions;
	} );

	When( /^I set did you mean suggester option (.+) to (.+)$/, function (varname, value) {
		this.didyoumeanOptions = this.didyoumeanOptions || {};
		this.didyoumeanOptions[varname] = value;
	} );

	Then( /^there are no did you mean suggestions from the api$/, function () {
		// TODO: This is actually a *did you mean* suggestion
		return withApi( this, () => {
			expect( this.apiResponse.query.searchinfo ).to.not.include.keys( 'suggestion' );
		} );
	} );

	Then( /^(.+?)(?: or (.+))? is the did you mean suggestion from the api$/, function ( first, second ) {
		// TODO: This is actually a *did you mean* suggestion
		return withApi( this, () => {
			expect( this.apiResponse.query.searchinfo ).to.include.any.keys( 'suggestionsnippet', 'rewrittenquerysnippet' );
			var suggestion = this.apiResponse.query.searchinfo.suggestionsnippet ||
				this.apiResponse.query.searchinfo.rewrittenquerysnippet;
			suggestion = suggestion.replace(/<em>/g, "*").replace(/<\/em>/g, "*").replace(/&quot;/g, '"');
			if ( second ) {
				expect( suggestion ).to.be.oneOf( [ first, second ] );
			} else {
				expect( suggestion ).to.equal( first );
			}
		} );
	} );

});
