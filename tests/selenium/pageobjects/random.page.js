'use strict';
const Page = require( 'wdio-mediawiki/Page' );

class RandomPage extends Page {
	async open() {
		await super.openTitle( 'Special:RandomPage' );
	}
}
module.exports = new RandomPage();
