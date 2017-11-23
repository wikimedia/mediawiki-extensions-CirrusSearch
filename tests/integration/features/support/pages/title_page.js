/*jshint esversion: 6,  node:true */

/**
 * Base page denoting a Title (can be article or special pages).
 */

const Page = require('./page');

class TitlePage extends Page {

	constructor( title ){
		super( `/wiki/${title}` );
		this._title = title || '';
	}

	get url() {
		return this._url;
	}
	set url( title ) {
		super.url =  `/wiki/${title}`;
	}

	title( title ) {
		if ( !title ) {
			return this._title;
		} else {
			this.url = title;
			this._title = title;
			return this;
		}
	}
}
module.exports = TitlePage;
