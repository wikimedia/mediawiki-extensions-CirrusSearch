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
		return browser.elements(".searchresults ul.mw-search-results").value.length > 0;
	}

	get_warnings() {
		return this.collect_element_texts(".searchresults div.warningbox p");
	}

	has_warnings() {
		return this.get_warnings().length > 0;
	}

	get_errors() {
		return this.collect_element_texts(".searchresults div.errorbox p");
	}

	has_errors() {
		return this.get_errors().length > 0;
	}

	has_create_page_link() {
		return browser.elements(".searchresults p.mw-search-createlink a.new").value.length === 1;
	}

	is_on_srp() {
		return browser.elements("form#search div#mw-search-top-table").value.length > 0 ||
			browser.elements("form#powersearch div#mw-search-top-table").value.length > 0;
	}

	set search_query( search ) {
		browser.setValue( 'div#searchText input[name="search"]', search );
	}

	get search_query() {
		return browser.getValue( 'div#searchText input[name="search"]' );
	}

	get_result_element_at( nth ) {
		let resultLink = this.results_block().element( `a[data-serp-pos=\"${nth-1}\"]` );
		if ( !resultLink.isExisting() ) {
			return null;
		}
		return resultLink.element("..");
	}

	get_result_image_link_at( nth ) {
		let resElem = this.get_result_element_at( nth );
		if ( resElem === null ) {
			return null;
		}
		// Image links are inside a table
		// move to the tr parent to switch the td holding the images
		// <tbody>
		//  <tr>
		//    <td>[THUMB IMAGE LINK BLOCK]</td>
		//    <td>[RESULT ELEMENT BLOCK] position returned by get_result_element_at</td>
		//  </tr>
		// </tbody>
		let tr = resElem.element("..");
		if ( tr.getTagName() !== 'tr' ) {
			return null;
		}
		let imageTag = tr.element( 'td a.image img' );
		if ( imageTag.isExisting() ) {
			return imageTag.getAttribute( 'src' );
		}
		return null;
	}

	has_search_data_in_results( data ) {
		return this.results_block().element( `div.mw-search-result-data*=${data}`).isExisting();
	}

	get_search_alt_title_at( nth ) {
		let resultBlock = this.get_result_element_at( nth );
		if ( resultBlock === null ) {
			return null;
		}
		let elt = resultBlock.element("span.searchalttitle");
		if ( elt.isExisting() ) {
			return elt.getText();
		}
		return null;
	}

	get_result_at( nth ) {
		return this.results_block().getAttribute( `a[data-serp-pos=\"${nth-1}\"]`, 'title' );
	}

	in_search_results( title ) {
		let elt = this.results_block().element(`a[title="${title}"]` );
		return elt.isExisting();
	}

	results_block() {
		let elt = browser.elements( "div.searchresults" );

		if ( !elt.value ) {
			throw new Error("Cannot locate search results block, are you on the SRP?");
		}
		return elt;
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