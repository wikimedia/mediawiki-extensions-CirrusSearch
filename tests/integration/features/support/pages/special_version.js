/*jshint esversion: 6, node:true */

const TitlePage = require('./title_page');

class SpecialVersion extends TitlePage {
	constructor( title ) {
		super( title );
	}

	software_table_row( name ) {
		return this.table('#sv-software').element( 'td=' + name ).value;
	}
}

module.exports = new SpecialVersion( 'Special:Version' );
