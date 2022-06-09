/**
 * Base page denoting a Title (can be article or special pages).
 */

'use strict';

const Page = require( './page' );

class TitlePage extends Page {
	constructor( title, extra_url_params ) {
		super();
		if ( title ) {
			this.url_params = 'title=' + title;
			if ( extra_url_params ) {
				this.url_params += ' &' + extra_url_params;
			}
		}
	}
}
module.exports = TitlePage;
