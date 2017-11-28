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
}

module.exports = new SearchResultsPage();