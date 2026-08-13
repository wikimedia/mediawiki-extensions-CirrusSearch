import Page from 'wdio-mediawiki/Page';

class RandomPage extends Page {
	async open() {
		await super.openTitle( 'Special:RandomPage' );
	}
}
export default new RandomPage();
