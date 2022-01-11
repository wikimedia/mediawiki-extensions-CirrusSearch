// TODO: Incomplete
// Page showing the article with some actions.  This is the page that everyone
// is used to reading on wikipedia.  My mom would recognize this page.

'use strict';

const TitlePage = require( './title_page' );

class ArticlePage extends TitlePage {

	get articleTitle() {
		return this.title_element().getText();
	}

	title_element() {
		return browser.$( 'h1#firstHeading' );
	}

	/**
	 * Performs a search by submitting the search form. The search button
	 * is harder to automate as it only appears when focused, going back
	 * and forth between clickable and not-clickable in vector 2.
	 */
	submit_search_top_right() {
		browser.$( '#searchform [name=search]' ).keys( '\n' );
	}

	has_search_suggestions() {
		return this.get_search_suggestions().length > 0;
	}

	get_search_suggestion_at( nth ) {
		nth--;
		const suggestions = this.get_search_suggestions();
		return suggestions.length > nth ? suggestions[ nth ] : null;
	}

	get_search_suggestions() {
		const selector = '.wvui-typeahead-suggestion__title';
		browser.waitUntil(
			() => browser.$( selector ).isExisting(),
			{ timeout: 10000 }
		);
		return this.collect_element_texts( selector );
	}

	set search_query_top_right( search ) {
		browser.$( '#searchform [name=search]' ).setValue( search );
	}

	get search_query_top_right() {
		return browser.getValue( '#searchform [name=search]' );
	}
}

module.exports = new ArticlePage();
