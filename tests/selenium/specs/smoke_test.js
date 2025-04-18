'use strict';
const assert = require( 'assert' ),
	ArticlePage = require( '../../integration/features/support/pages/article_page' ),
	RandomPage = require( '../pageobjects/random.page' );

/**
 * Smoke test for CirrusSearch
 * meant to be run with enwiki on the beta cluster
 * prerequisites:
 *  - the África page is expected to exist
 */
describe( 'Smoke test for search', () => {

	/**
	 * Given I am at a random page
	 * When I type main p into the search box
	 * Then suggestions should appear
	 * And Main Page is the first suggestion
	 */
	it( 'Search suggestions', async () => {
		await RandomPage.open();
		await ArticlePage.set_search_query_top_right( 'main p' );
		assert.ok( await ArticlePage.has_search_suggestions() );
		const expectedSuggestion = 'Main Page';
		assert.equal( await ArticlePage.get_search_suggestion_at( 1 ), expectedSuggestion,
			`${ expectedSuggestion } is the first suggestion` );
	} );

	/**
	 * Given I am at a random page
	 * When I type ma into the search box
	 * And I click the search button
	 * Then I am on a page titled Search results
	 */
	it( 'Fill in search term and click search', async () => {
		await RandomPage.open();
		await ArticlePage.search_query_top_right( 'ma' );
		await ArticlePage.submit_search_top_right();
		const expectedPage = 'Search results';
		assert.equal( await ArticlePage.articleTitle(), expectedPage,
			`I am on a page named ${ expectedPage }` );
	} );

	/**
	 * Given I am at a random page
	 * When I search for África
	 * Then I am on a page titled África
	 */
	it( 'Search with accent yields result page with accent', async () => {
		await RandomPage.open();
		await ArticlePage.set_search_query_top_right( 'África' );
		await ArticlePage.submit_search_top_right();
		const expectedPage = 'África';
		assert.equal( await ArticlePage.articleTitle(), expectedPage,
			`I am on a page named ${ expectedPage }` );
	} );

} );
