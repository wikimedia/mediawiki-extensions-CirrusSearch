'use strict';

const TitlePage = require( './title_page' );

class SpecialUndelete extends TitlePage {
	constructor() {
		// Haxing fuzzy into the url like this feels hacky.
		super( 'Special:Undelete', 'fuzzy=1' );
	}

	async set_search_input( search ) {
		const elt = await browser.$( '#prefix' );
		return elt.setValue( search );
	}

	async get_search_input() {
		const elt = await browser.$( '#prefix' );
		return elt.getValue();
	}

	async click_search_button() {
		const elt = await browser.$( '#searchUndelete' );
		await elt.click();
	}

	// nth is 1-indexed, not 0 like might be expected
	async get_result_at( nth ) {
		const elt = await browser.$( `.undeleteResult:nth-child(${ nth }) a` );
		return elt.getText();
	}
}

module.exports = new SpecialUndelete();
