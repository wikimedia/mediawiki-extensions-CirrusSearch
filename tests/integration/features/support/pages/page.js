/*jshint esversion: 6,  node:true */
/*global browser */

/**
 * The Page object contains shortcuts and properties
 */

class Page {
	constructor(){
		// tag selector shortcut.
		// analogous to Ruby's link(:create_link, text: "Create") etc.
		// assuming first param is a selector, second is text.
		['h1',
		'table',
		'td',
		'a',
		'ul',
		'li',
		'button',
		'textarea',
		'div',
		'span',
		'p',
		'input[type=text]',
		'input[type=submit]',
		].forEach( ( el ) => {
			var alias = el;
			switch ( el ) {
				case 'a': alias = 'link'; break;
				case 'input[type=text]': alias = 'text_field'; break;
				case 'textarea': alias = 'text_area'; break;
				case 'p': alias = 'paragraph'; break;
				case 'ul': alias = 'unordered_list'; break;
				case 'td': alias = 'cell'; break;
			}
			// the text option here doesn't work on child selectors
			// when more that one element is returned.
			// so "table.many-tables td=text" doesn't work!
			this[el] = this[alias] = ( selector, text ) => {
				let s = selector || '';
				let t = ( text ) ? '='+text : '';
				let sel = el + s + t;
				let elems = browser.elements( sel );
				return elems;
			};
		} );
	}

	collect_element_texts( selector ) {
		let elements = browser.elements(selector).value;
		let texts = [];
		for ( let text of elements ) {
			texts.push( text.getText() );
		}
		return texts;
	}

	collect_element_attribute( attr, selector ) {
		let elements = browser.elements(selector).value;
		let texts = [];
		for ( let elt of elements ) {
			texts.push(elt.getAttribute(attr));
		}
		return texts;
	}


	get url() {
		return this._url;
	}
	set url( url ) {
		this._url = url;
	}

	login( world, wiki = false ) {
		let config = wiki ?
			world.config.wikis[wiki] :
			world.config.wikis[ world.config.wikis.default ];
		world.visit( '/wiki/Special:UserLogin' );
		browser.setValue( '#wpName1', config.username );
		browser.setValue( '#wpPassword1', config.password );
		browser.click( '#wpLoginAttempt' );
	}
}
module.exports = Page;
