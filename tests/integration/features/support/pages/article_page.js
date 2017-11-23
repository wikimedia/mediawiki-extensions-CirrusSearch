/*jshint esversion: 6,  node:true */
/*global browser */

// TODO: Incomplete
// Page showing the article with some actions.  This is the page that everyone
// is used to reading on wikpedia.  My mom would recognize this page.

const TitlePage = require('./title_page');

class ArticlePage extends TitlePage {

	get articleTitle() {
		return browser.getText("h1#firstHeading");
	}

	/**
	 * Performs a search using the search button top-right
	 */
	click_search_top_right() {
		browser.click( "#simpleSearch #searchButton" );
	}

	set search_query_top_right( search ) {
		browser.setValue( "#simpleSearch #searchInput", search );
	}

	get search_query_top_right() {
		return browser.getValue('#simpleSearch #searchInput');
	}
}

module.exports = new ArticlePage();