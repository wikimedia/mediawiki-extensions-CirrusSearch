'use strict';
const Page = require( '../../../../../tests/selenium/pageobjects/page' );

class RandomPage extends Page {
	open() {
		super.open( 'Special:RandomPage' );
	}
}
module.exports = new RandomPage();
