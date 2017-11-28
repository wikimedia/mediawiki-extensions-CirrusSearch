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
		return browser.elements("form#search div#mw-search-top-table").value.length > 0 ||
			browser.elements("form#powersearch div#mw-search-top-table").value.length > 0;
	}

	set search_query(search ) {
		browser.setValue( 'div#searchText input[name="search"]', search );
	}

	get search_query() {
		browser.getValue( 'div#searchText input[name="search"]' );
	}

	get_result_at( nth ) {
		return browser.getAttribute( `ul.mw-search-results li div.mw-search-result-heading a[data-serp-pos=\"${nth-1}\"]`, 'title' );
	}

	click_search() {
		let forms = ['form#powersearch', 'form#search'];
		for( let form of forms ) {
			let elt = browser.element( form );
			if ( elt.value ) {
				elt.click('button[type="submit"]');
				return;
			}
		}
		throw new Error("Cannot click the search button, are you on the Search page?");
	}

	/**
	 * @param {string} filter
	 */
	click_filter( filter ) {
		let linkSel = `a=${filter}`;
		browser.element( 'div.search-types' ).click( linkSel );
	}

	/**
	 * @param {Array.<string>} namespaceLabels
	 * @param {boolean} first true to select first, false to select all
	 */
	select_namespaces( namespaceLabels, first ) {
		let elt = browser.element( 'form#powersearch fieldset#mw-searchoptions' );
		if ( !elt.value ) {
			throw new Error( "Cannot find the namespace filters, did you click on 'Advanced' first?" );
		}
		for ( let nsLabel of namespaceLabels ) {
			let labelSel = `label=${nsLabel}`;
			let label = elt.element( labelSel );
			if ( label.value ) {
				label.click();
				if ( first ) {
					return;
				}
			} else if ( !first ) {
				throw new Error( `Count not find namespace labeled as ${nsLabel}` );
			}
		}
		if ( first ) {
			throw new Error( `Count not find any namespace link labeled as ${namespaceLabels.join()}` );
		}
	}
}

module.exports = new SearchResultsPage();