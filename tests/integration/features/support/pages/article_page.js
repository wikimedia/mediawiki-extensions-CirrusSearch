/*jshint esversion: 6,  node:true */
/*global browser */

// TODO: Incomplete
// Page showing the article with some actions.  This is the page that everyone
// is used to reading on wikpedia.  My mom would recognize this page.

const TitlePage = require('./title_page');

class ArticlePage extends TitlePage {

	get articleTitle() {
		return this.title_element().getText();
	}

	title_element() {
		return browser.element( "h1#firstHeading" );
	}

	/**
	 * Performs a search using the search button top-right
	 */
	click_search_top_right() {
		browser.click( "#simpleSearch #searchButton" );
	}

	has_search_suggestions() {
		return this.get_search_suggestions().length > 0;
	}

	get_search_suggestion_at(nth) {
		nth--;
		let suggestions = this.get_search_suggestions();
		return suggestions.length > nth ? suggestions[nth] : null;
	}

	get_search_suggestions() {
		let selector = '.suggestions .suggestions-results a.mw-searchSuggest-link';
		browser.waitForVisible(selector, 5000);
		return this.collect_element_attribute('title', selector);
	}

	set search_query_top_right( search ) {
		browser.setValue( "#simpleSearch #searchInput", search );
	}

	get search_query_top_right() {
		return browser.getValue('#simpleSearch #searchInput');
	}
}

module.exports = new ArticlePage();