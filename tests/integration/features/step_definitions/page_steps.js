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
	TitlePage = require('../support/pages/title_page'),
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
		return this.visit( new TitlePage( title ) );
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

	When( /^I get api suggestions for (.*?)(?: using the (.+) profile)?(?: on namespaces (\d+(?:,\d+)*))?$/, function( search, profile, namespaces ) {
		// TODO: Add step helper
		return this.stepHelpers.suggestionsWithProfile( search, profile || "fuzzy", namespaces );
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
				// Asserts that title is found within the strings that make up found.
				// ex: found = ['foo bar baz'], title = 'bar' should pass.
				// Chai doesnt (yet) have a native assertion for this:
				// https://github.com/chaijs/chai/issues/858
				let ok = found.reduce( ( a, b ) => a || b.indexOf( title ) > -1, false );
				expect( ok, `expected ${JSON.stringify(found)} to include "${title}"` ).to.equal(true);
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

	Then( /^(.*) is( not)? part of the api search result$/, function ( title, not_searching ) {
		return withApi( this, () => {
			// Chai doesnt (yet) have a native assertion for this:
			// https://github.com/chaijs/chai/issues/858
			let found = this.apiResponse.query.search.map( ( result ) => result.title );
			let ok = found.reduce( ( a, b ) => a || b.indexOf( title ) > -1, false );
			let msg = `Expected ${JSON.stringify(found)} to${not_searching ? ' not' : ''} include ${title}`;

			if ( not_searching ) {
				expect( ok, msg ).to.equal(false);
			} else {
				expect( ok, msg ).to.equal(true);
			}
		} );
	} );

	When( /^I api search( with rewrites enabled)?(?: with query independent profile ([^ ]+))?(?: with offset (\d+))?(?: in the (.*) language)?(?: in namespaces? (\d+(?: \d+)*))?(?: on ([a-z]+))? for (.*)$/, function ( enableRewrites, qiprofile, offset, lang, namespaces, wiki, search ) {
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
		search = search.replace(/%\{\\u([\dA-Fa-f]{4,6})\}%/g, ( match, codepoint ) => JSON.parse( `"\\u${codepoint}"` ) );
		let stepHelpers = this.stepHelpers;
		if ( wiki ) {
			stepHelpers = this.stepHelpers.onWiki(wiki);
		}
		return stepHelpers.searchFor( search, options );
	} );

	Then( /there are no errors reported by the api/, function () {
		return withApi( this, () => {
			expect( this.apiError ).to.equal(undefined);
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
			let suggestion = this.apiResponse.query.searchinfo.suggestionsnippet ||
				this.apiResponse.query.searchinfo.rewrittenquerysnippet;
			suggestion = suggestion.replace(/<em>/g, "*").replace(/<\/em>/g, "*").replace(/&quot;/g, '"');
			if ( second ) {
				expect( suggestion ).to.be.oneOf( [ first, second ] );
			} else {
				expect( suggestion ).to.equal( first );
			}
		} );
	} );

	Then( /^(.+) is( in)? the highlighted (.+) of the (.+) api search result$/, function ( expected, in_ok, key, index ) {
		withApi( this, () => {
			let position = 'first second third fourth fifth sixth seventh eighth ninth tenth'.split( ' ' ).indexOf( index );
			expect( this.apiResponse.query.search ).to.have.lengthOf.gt( position );

			if ( key === 'title' && expected.indexOf( '*' ) > -1) {
				key = 'titlesnippet';
			}
			expect( this.apiResponse.query.search[position] ).to.include.keys( key );
			let snippet = this.apiResponse.query.search[position][key].replace(
				/<span class="searchmatch">(.*?)<\/span>/g, '*$1*');
			if ( in_ok ) {
				expect( snippet ).to.include( expected );
			} else {
				expect( snippet ).to.equal( expected );
			}
		} );
	} );

	Then( /^the first api search result is a match to file content$/, function () {
		withApi( this, () => {
			expect( this.apiResponse.query.search[0].isfilematch ).to.equal(true);
		} );
	} );

	Then( /^I locate the page id of (.*) and store it as (%.*%)$/, function ( title, varname ) {
		return Promise.coroutine( function* () {
			this.searchVars[varname] = yield this.stepHelpers.pageIdOf( title );
		} ).call( this );
	} );

	Then( /^I wait (\d+) seconds/, function ( seconds ) {
		return this.stepHelpers.waitForMs( seconds * 1000 );
	} );

	Then( /^I delete (.*)$/, function ( title ) {
		return this.stepHelpers.deletePage( title );
	} );

	Then( /^I globally freeze indexing$/, Promise.coroutine( function* () {
		let client = yield this.onWiki();
		yield client.request( {
			action: 'cirrus-freeze-writes',
		} );
	} ) );

	Then( /^I globally thaw indexing$/, Promise.coroutine( function* () {
		let client = yield this.onWiki();
		yield client.request( {
			action: 'cirrus-freeze-writes',
			thaw: true
		} );
	} ) );

	Then( /^a file named (.*) exists( on commons)? with contents (.*) and description (.*)$/, function (title, on_commons, fileName, description) {
		let stepHelpers = this.stepHelpers;
		if ( on_commons ) {
			stepHelpers = stepHelpers.onWiki( 'commons' );
		}
		return stepHelpers.uploadFile( title, fileName, description );
	} );

	Then(/^within (\d+) seconds (.*) has (.*) as local_sites_with_dupe$/, function (seconds, page, value) {
		return Promise.coroutine( function* () {
			let stepHelpers = this.stepHelpers.onWiki( 'commons' );
			let time = new Date();
			let found = false;
			main: do {
				let content = yield stepHelpers.getCirrusIndexedContent( page );
				if ( content ) {
					for ( let doc of content ) {
						found = doc.source && doc.source.local_sites_with_dupe &&
							doc.source.local_sites_with_dupe.indexOf( value ) > -1;
						if ( found ) break main;
					}
				}
				yield stepHelpers.waitForMs( 100 );
			} while ( ( new Date() - time ) < ( seconds * 1000 ) );
			let msg = `Expected ${page} on commons to have ${value} in local_sites_with_dupe within ${seconds}s.`;
			expect( found, msg ).to.equal(true);
		} ).call( this );
	} );

	Then(/^I am on a page titled (.*)$/, function( title ) {
		expect(ArticlePage.articleTitle, `I am on ${title}`).to.equal(title);
	} );

	Given(/^I am at a random page$/, function() {
		return this.visit( new TitlePage( 'Special:Random' ) );
	} );

	When(/^I set More Like This Options to ([^ ]+) field, word length to (\d+) and I api search for (.+)$/, function ( field, length, search ) {
		let options = {
			cirrusMtlUseFields: 'yes',
			cirrusMltFields: field,
			cirrusMltMinTermFreq: 1,
			cirrusMltMinDocFreq: 1,
			cirrusMltMinWordLength: length,
			srlimit: 20
		};
		return this.stepHelpers.searchFor( search, options );
	} );

	When(/^I set More Like This Options to ([^ ]+) field, percent terms to match to (\d+%) and I api search for (.+)$/, function( field, percent, search ) {
		let options = {
			cirrusMtlUseFields: 'yes',
			cirrusMltFields: field,
			cirrusMltMinTermFreq: 1,
			cirrusMltMinDocFreq: 1,
			cirrusMltMinWordLength: 0,
			cirrusMltMinimumShouldMatch: percent,
			srlimit: 20
		};
		return this.stepHelpers.searchFor( search, options );
	} );

	When(/^I set More Like This Options to bad settings and I api search for (.+)$/, function ( search ) {
		let options = {
			cirrusMtlUseFields: 'yes',
			cirrusMltFields: 'title',
			cirrusMltMinTermFreq: 100,
			cirrusMltMinDocFreq: 200000,
			cirrusMltMinWordLength: 190,
			cirrusMltMinimumShouldMatch: '100%',
			srlimit: 20
		};
		return this.stepHelpers.searchFor( search, options );
	} );
});
