/*jshint esversion: 6,  node:true */
/*global browser */

// TODO: Incomplete
// Page showing the article with some actions.  This is the page that everyone
// is used to reading on wikpedia.  My mom would recognize this page.

const TitlePage = require('./title_page');

class ArticlePage extends TitlePage {
	constructor(){
		super();
	}

	get articleTitle() {
		return browser.getText("h1#firstHeading");
	}
}

module.exports = new ArticlePage();