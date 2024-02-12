'use strict';

const TitlePage = require( './title_page' );

class SpecialUndelete extends TitlePage {
	constructor() {
		// Haxing fuzzy into the url like this feels hacky.
		super( 'Special:Undelete', 'fuzzy=1' );
	}

	async set_search_input( search ) {
		return browser.$( '#prefix' ).setValue( search );
	}

	async get_search_input() {
		return browser.$( '#prefix' ).getValue();
	}

	async click_search_button() {
		await browser.$( '#searchUndelete' ).click();
	}

	// nth is 1-indexed, not 0 like might be expected
	async get_result_at( nth ) {
		return browser.$( `.undeleteResult:nth-child(${ nth }) a` ).getText();
	}
}

module.exports = new SpecialUndelete();
