'use strict';

const Page = require( './page' );

class SearchResultsPage extends Page {

	/**
	 * Open the Search results searching for search.
	 *
	 * Does not do anything on it's own. Must be used with the world helper:
	 *   world.visit( SearchResultsPage.search( 'catapult' ) );
	 *
	 * @param {string} search
	 * @return {SearchResultsPage}
	 */
	search( search ) {
		this.url_params = `title=Special:Search&search=${ encodeURIComponent( search ) }`;
		return this;
	}

	async has_search_results() {
		const elt = await browser.$( '.searchresults ul.mw-search-results' );
		return elt.isExisting();
	}

	async get_warnings() {
		return this.collect_element_texts( '.searchresults div.mw-warning-box p' );
	}

	async has_warnings() {
		const warnings = await this.get_warnings();
		return warnings.length > 0;
	}

	get_errors() {
		return this.collect_element_texts( '.searchresults div.mw-error-box p' );
	}

	has_errors() {
		return this.get_errors().length > 0;
	}

	async has_create_page_link() {
		const elt = await browser.$( '.searchresults p.mw-search-createlink a.new' );
		return elt.isExisting();
	}

	async is_on_srp() {
		const search = await browser.$( 'form#search div#mw-search-top-table' );
		const powersearch = await browser.$( 'form#powersearch div#mw-search-top-table' );
		return await search.isExisting() || await powersearch.isExisting();
	}

	async set_search_query( search ) {
		const elt = browser.$( 'div#searchText input[name="search"]' );
		await elt.setValue( search );
	}

	async get_search_query() {
		const elt = await browser.$( 'div#searchText input[name="search"]' );
		return elt.getValue();
	}

	async get_result_element_at( nth ) {
		// Needs to use xpath to access an arbitrary parent from the child anchor
		const resultLink = await browser.$(
			`//a[@data-serp-pos="${ nth - 1 }"]//ancestor::li[contains(@class, "mw-search-result")]` );
		if ( !await resultLink.isExisting() ) {
			return null;
		}
		return resultLink.parentElement();
	}

	async get_result_image_link_at( nth ) {
		const resElem = await this.get_result_element_at( nth );
		if ( resElem === null ) {
			return null;
		}
		const imageTag = await resElem.$( '.searchResultImage-thumbnail img' );
		if ( await imageTag.isExisting() ) {
			return imageTag.getAttribute( 'src' );
		}
		return null;
	}

	async has_search_data_in_results( data ) {
		const elt = await this.results_block();
		const subelt = await elt.$( `div.mw-search-result-data*=${ data }` );
		return subelt.isExisting();
	}

	async get_search_alt_title_at( nth ) {
		const resultBlock = await this.get_result_element_at( nth );
		if ( resultBlock === null ) {
			return null;
		}
		const elt = await resultBlock.$( 'span.searchalttitle' );
		if ( await elt.isExisting() ) {
			return elt.getText();
		}
		return null;
	}

	async get_result_at( nth ) {
		const elt = await this.results_block();
		return elt.$( `a[data-serp-pos="${ nth - 1 }"]` ).getAttribute( 'title' );
	}

	async in_search_results( title ) {
		const elt = await this.results_block();
		return elt.$( `a[title="${ title }"]` ).isExisting();
	}

	async results_block() {
		const elt = await browser.$( 'div.searchresults' );
		if ( !( await elt.isExisting() ) ) {
			throw new Error( 'Cannot locate search results block, are you on the SRP?' );
		}
		return elt;
	}

	async click_search() {
		const forms = [ 'form#powersearch', 'form#search' ];
		for ( const form of forms ) {
			const elt = await browser.$( form );
			if ( await elt.isExisting() ) {
				const button = await elt.$( 'button[type="submit"]' );
				return button.click();
			}
		}
		throw new Error( 'Cannot click the search button, are you on the Search page?' );
	}

	/**
	 * @param {string} filter
	 */
	async click_filter( filter ) {
		const linkSel = `a=${ filter }`;
		const divs = await browser.$( 'div.search-types' );
		const link = await divs.$( linkSel );
		return link.click();
	}

	/**
	 * @param {Array.<string>} namespaceLabels
	 * @param {boolean} first true to select first, false to select all
	 */
	async select_namespaces( namespaceLabels, first ) {
		const elt = await browser.$( 'form#powersearch fieldset#mw-searchoptions' );
		if ( !await elt.isExisting() ) {
			throw new Error( "Cannot find the namespace filters, did you click on 'Advanced' first?" );
		}
		for ( const nsLabel of namespaceLabels ) {
			const labelSel = `label=${ nsLabel }`;
			const label = await elt.$( labelSel );
			if ( await label.isExisting() ) {
				await label.click();
				if ( first ) {
					return;
				}
			} else if ( !first ) {
				throw new Error( `Count not find namespace labeled as ${ nsLabel }` );
			}
		}
		if ( first ) {
			throw new Error( `Count not find any namespace link labeled as ${ namespaceLabels.join() }` );
		}
	}
}

module.exports = new SearchResultsPage();
