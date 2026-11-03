/**
 * The Page object contains shortcuts and properties
 */

'use strict';

class Page {
	constructor() {
		// tag selector shortcut.
		// analogous to Ruby's link(:create_link, text: "Create") etc.
		// assuming first param is a selector, second is text.
		[ 'h1',
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
			'input[type=submit]'
		].forEach( ( el ) => {
			let alias = el;
			switch ( el ) {
				case 'a':
					alias = 'link';
					break;
				case 'input[type=text]':
					alias = 'text_field';
					break;
				case 'textarea':
					alias = 'text_area';
					break;
				case 'p':
					alias = 'paragraph';
					break;
				case 'ul':
					alias = 'unordered_list';
					break;
				case 'td':
					alias = 'cell';
					break;
			}
			// the text option here doesn't work on child selectors
			// when more that one element is returned.
			// so "table.many-tables td=text" doesn't work!
			this[ el ] = this[ alias ] = ( selector, text ) => {
				const s = selector || '';
				const t = ( text ) ? '=' + text : '';
				const sel = el + s + t;
				return $$( sel );
			};
		} );
	}

	async collect_element_texts( selector ) {
		const elements = await $$( selector );
		const texts = [];
		for ( const text of elements ) {
			texts.push( await text.getText() );
		}
		return texts;
	}

	get url_params() {
		// TODO: Why get/set and not a raw param?
		return this._url_params;
	}

	set url_params( url_params ) {
		this._url_params = url_params;
	}

	async login( world, wiki = false ) {
		const config = wiki ?
			world.config.wikis[ wiki ] :
			world.config.wikis[ world.config.wikis.default ];
		await world.visit( 'Special:UserLogin' );
		const name = await browser.$( '#wpName1' );
		await name.setValue( config.username );

		const password = await browser.$( '#wpPassword1' );
		await password.setValue( config.password );

		const login = await browser.$( '#wpLoginAttempt' );
		await login.click();

		// The click can return before the login POST completes. If the next
		// navigation starts before then, it cancels the POST and the browser
		// stays logged out.
		const isLoggedIn = () => browser.execute(
			// eslint-disable-next-line no-undef
			( expected ) => typeof mw !== 'undefined' && mw.config.get( 'wgUserName' ) === expected,
			config.username
		);
		const hasSkipReset = () => browser.$( '#mw-input-skipReset' ).isExisting();
		await browser.waitUntil(
			async () => await isLoggedIn() || await hasSkipReset(),
			{ timeout: 15000, timeoutMsg: 'Login did not complete' }
		);
		// skip password reset, not always present?
		if ( await hasSkipReset() ) {
			await browser.$( '#mw-input-skipReset' ).click();
			await browser.waitUntil( isLoggedIn,
				{ timeout: 15000, timeoutMsg: 'Login did not complete after skipping password reset' } );
		}
	}
}
module.exports = Page;
