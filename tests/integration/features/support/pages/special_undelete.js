/*jshint esversion: 6, node:true */
/*global browser */

var TitlePage = require('./title_page');

class SpecialUndelete extends TitlePage {
	constructor() {
		// Haxing fuzzy into the url like this feels hacky.
		super( 'Special:Undelete?fuzzy=1' );
	}

	set search_input( search ) {
		browser.setValue( '#prefix', search );
	}

	get search_input() {
		browser.getValue( '#prefix' );
	}

	click_search_button() {
		browser.click( '#searchUndelete' );
	}

	// nth is 1-indexed, not 0 like might be expected
	get_result_at( nth ) {
		return browser.getText( `.undeleteResult:nth-child(${nth}) a` );
	}
}

module.exports = new SpecialUndelete();
