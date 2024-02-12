'use strict';

const { Given, When, Then } = require( '@cucumber/cucumber' ),
	SearchResultsPage = require( '../support/pages/search_results_page' ),
	ArticlePage = require( '../support/pages/article_page' ),
	TitlePage = require( '../support/pages/title_page' ),
	expect = require( 'chai' ).expect;

When( /^I go search for (.+)$/, async function ( title ) {
	return this.visit( SearchResultsPage.search( title ) );
} );

Then( /^there are no search results/, async function () {
	expect( await SearchResultsPage.has_search_results(), 'there are no search results' ).to.equal( false );
} );

When( /^I search for (.+)$/, async function ( search ) {
	// If on the SRP already use the main search
	if ( await SearchResultsPage.is_on_srp() ) {
		await SearchResultsPage.set_search_query( search );
		return SearchResultsPage.click_search();
	} else {
		await ArticlePage.set_search_query_top_right( search );
		return ArticlePage.submit_search_top_right();
	}
} );

Then( /^there is (no|a) link to create a new page from the search result$/, async function ( no_or_a ) {
	const msg = `there is ${ no_or_a } link to create a new page from the search result`;
	expect( await SearchResultsPage.has_create_page_link(), msg ).to.equal( no_or_a !== 'no' );
} );

Then( /^there is no warning$/, async function () {
	const msg = 'there is no warning';
	expect( await SearchResultsPage.has_warnings(), msg ).to.equal( false );
} );

Then( /^there are no errors reported$/, async function () {
	const msg = 'there are no errors reported';
	expect( await SearchResultsPage.has_errors(), msg ).to.equal( false );
} );

Then( /^(.+) is the first search result( and has an image link)?$/, async function ( result, imagelink ) {
	const msg = `${ result } is the first search result`;
	if ( result === 'none' ) {
		expect( await SearchResultsPage.has_search_results(), msg ).to.equal( false );
	} else {
		expect( await SearchResultsPage.is_on_srp(), msg ).to.equal( true );
		expect( await SearchResultsPage.has_search_results(), msg ).to.equal( true );
		expect( await SearchResultsPage.get_result_at( 1 ), msg ).to.equal( result );
		if ( imagelink ) {
			expect( await SearchResultsPage.get_result_image_link_at( 1 ), `${ msg } : imagelink must exist` ).to.not.equal( null );
		}
	}
} );

Then( /^(.+) is( not)? in the search results$/, async function ( result, not ) {
	const msg = `${ result } is${ not === null ? '' : not } in the search results`;
	expect( await SearchResultsPage.is_on_srp(), msg ).to.equal( true );
	if ( not === null ) {
		expect( await SearchResultsPage.has_search_results(), msg ).to.equal( true );
	}
	expect( await SearchResultsPage.in_search_results( result ), msg ).to.equal( not === null );
} );

Given( /^I am at the search results page$/, async function () {
	return this.visit( new TitlePage( 'Special:Search' ) );
} );

When( /^I click the (.+) link$/, async function ( filter ) {
	return SearchResultsPage.click_filter( filter );
} );

When( /^I click the (.+) labels?$/, async function ( filter ) {
	const and_labels = filter.split( /, /, 10 );
	for ( const labels of and_labels ) {
		const or_labels = labels.split( / or /, 10 );
		await SearchResultsPage.select_namespaces( or_labels, true );
	}
} );

Then( /^the title still exists$/, async function () {
	const msg = 'the title still exists';
	const elt = await ArticlePage.title_element();
	expect( await elt.isExisting(), msg ).to.equal( true );
} );

Then( /^there is not alttitle on the first search result$/, async function () {
	const msg = 'there is not alttitle on the first search result';
	expect( await SearchResultsPage.get_search_alt_title_at( 1, msg ) ).to.equal( null );
} );

Then( /^there are search results with \((.+)\) in the data$/, async function ( what ) {
	const msg = `there are search results with ${ what } in the data`;
	expect( await SearchResultsPage.is_on_srp() ).to.equal( true );
	expect( await SearchResultsPage.has_search_results(), msg ).to.equal( true );
	expect( await SearchResultsPage.has_search_data_in_results( what ), msg ).to.equal( true );
} );

Then( /^I type (.+) into the search box$/, async function ( search ) {
	return ArticlePage.set_search_query_top_right( search );
} );

Then( /^suggestions should appear$/, async function () {
	expect( await ArticlePage.has_search_suggestions() ).to.equal( true );
} );

Then( /^(.+) is the first suggestion$/, async function ( page ) {
	expect( await ArticlePage.get_search_suggestion_at( 1 ) ).to.equal( page );
} );

Then( /^I click the search button$/, async function () {
	return ArticlePage.submit_search_top_right();
} );
