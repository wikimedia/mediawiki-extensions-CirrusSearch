// TODO: Incomplete
// Page showing the article with some actions.  This is the page that everyone
// is used to reading on wikipedia.  My mom would recognize this page.

'use strict';

const TitlePage = require( './title_page' ),
	{ Key } = require( 'webdriverio' );

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
		const oldUrl = await browser.getUrl();
		const elt = await browser.$( '#searchform [name=search]' );
		await elt.addValue( Key.Enter );
		// This could send the user to an article or Special:Search, to wait for
		// the next page to load wait for a url change, and then documentReady.
		await browser.waitUntil(
			async () => ( await browser.getUrl() ) !== oldUrl,
			{ timeout: 10000, timeoutMsg: 'URL never changed after submitting form' }
		);
		/* global document */
		await browser.waitUntil(
			async () => ( await browser.execute( () => document.readyState ) ) === 'complete',
			{ timeout: 10000, timeoutMsg: 'New page did not finish loading' }
		);
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
		const selector = '.cdx-search-input .cdx-menu-item';
		await browser.waitUntil(
			async () => {
				const elt = await browser.$( selector );
				if ( !( await elt.isExisting() ) ) {
					return false;
				}
				if ( ( await elt.getText() ) === 'Loading search suggestions' ) {
					return false;
				}
				return true;
			},
			{ timeout: 10000, timeoutMsg: 'Search suggestions did not appear.' }
		);
		return this.collect_element_texts( selector );
	}

	async set_search_query_top_right( search ) {
		const elt = await browser.$( '#searchform [name=search]' );
		await elt.waitForClickable();
		return elt.setValue( search );
	}
}

module.exports = new ArticlePage();
