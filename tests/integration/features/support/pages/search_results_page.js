/*jshint esversion: 6,  node:true */
/*global browser */

const Page = require('./page');

class SearchResultsPage extends Page {

	/**
	 * Open the Search results searching for search
	 * @param {string} search
	 */
	search( search ) {
		this.url = `/w/index.php?search=${encodeURIComponent(search)}`;
		return this;
	}

	has_search_results() {
		return browser.elements(".searchresults p.mw-search-nonefound").value.length === 0;
	}

	get_warnings() {
		let elements = browser.elements(".searchresults div.warningbox p").value;
		let warnings = [];
		for ( let warning of elements ) {
			warnings.push( warning.getText() );
		}
		return warnings;
	}

	has_create_page_link() {
		return browser.elements(".searchresults p.mw-search-createlink a.new").value.length === 1;
	}

	is_on_srp() {
		// Q: why selecting form.search div.mw-search-top-table does not work?
		return browser.elements("form#search div#mw-search-top-table").value.length > 0;
	}

	set search_query(search ) {
		browser.setValue( 'div#searchText input[name="search"]', search );
	}

	get search_query() {
		browser.getValue( 'div#searchText input[name="search"]' );
	}

	get_result_at( nth ) {
		return browser.getText( `ul.mw-search-results li div.mw-search-result-heading a[data-serp-pos=\"${nth-1}\"]` );
	}

	click_search() {
		browser.click( "#simpleSearch #searchButton" );
	}
}

module.exports = new SearchResultsPage();