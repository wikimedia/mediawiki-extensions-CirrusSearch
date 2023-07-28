// TODO: Incomplete
// Page showing the article with some actions.  This is the page that everyone
// is used to reading on wikipedia.  My mom would recognize this page.

'use strict';

const TitlePage = require( './title_page' );

class ArticlePage extends TitlePage {

	async articleTitle() {
		const elt = await this.title_element();
		return elt.getText();
	}

	async title_element() {
		return browser.$( 'h1#firstHeading' );
	}

	/**
	 * Performs a search by submitting the search form. The search button
	 * is harder to automate as it only appears when focused, going back
	 * and forth between clickable and not-clickable in vector 2.
	 */
	async submit_search_top_right() {
		return browser.$( '#searchform [name=search]' ).keys( '\n' );
	}

	async has_search_suggestions() {
		const elt = await this.get_search_suggestions();
		return elt.length > 0;
	}

	async get_search_suggestion_at( nth ) {
		nth--;
		const suggestions = await this.get_search_suggestions();
		return suggestions.length > nth ? suggestions[ nth ] : null;
	}

	async get_search_suggestions() {
		const selector = '.cdx-search-result-title';
		await browser.waitUntil(
			async function () {
				return browser.$( selector ).isExisting();
			},
			{ timeout: { timeout: 10000 } }
		);
		return this.collect_element_texts( selector );
	}

	async set_search_query_top_right( search ) {
		return browser.$( '#searchform [name=search]' ).setValue( search );
	}

	async get_search_query_top_right() {
		return browser.$( '#searchform [name=search]' );
	}
}

module.exports = new ArticlePage();
