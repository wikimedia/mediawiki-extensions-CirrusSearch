<?php

namespace CirrusSearch;

/**
 * @covers \CirrusSearch\ExplainPrinter
 * @group CirrusSearch
 */
class ExplainPrinterTest extends CirrusTestCase {

	private const XSS = "<script>document.title='CIRRUS_XSS_EXEC'</script>";

	private static function response( array $overrides = [] ): array {
		return $overrides + [
			'description' => 'full_text search for \'catapult\'',
			'path' => 'my_wiki_content/_search',
			'result' => [ 'hits' => [ 'hits' => [ [
				'_id' => '123',
				'_score' => 1.5,
				'_source' => [ 'title' => 'Catapult' ],
				'_explanation' => [ 'value' => 1.5, 'description' => 'weight(text:catapult)' ],
			] ] ] ],
		];
	}

	private static function format( array $response ): string {
		return ( new ExplainPrinter( 'verbose' ) )->format( $response );
	}

	/** The heading carries the raw search term, so it must not reach the page as markup. */
	public static function provideHeadingFields(): array {
		return [
			'description' => [ 'description' ],
			'path' => [ 'path' ],
		];
	}

	/** @dataProvider provideHeadingFields */
	public function testHeadingFieldsAreEscaped( string $field ) {
		$html = self::format( self::response( [ $field => self::XSS ] ) );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '&lt;script', $html );
	}

	/** Highlight snippets are indexed page text, not markup. */
	public function testHighlightIsEscaped() {
		$html = self::format( self::response( [ 'result' => [ 'hits' => [ 'hits' => [
			self::response()['result']['hits']['hits'][0] + [
				'highlight' => [ 'text' => [ self::XSS ] ],
			],
		] ] ] ] ) );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '&lt;script', $html );
	}

	/** Escaping leaves the private-use highlight markers intact. */
	public function testHighlightMarkersSurviveEscaping() {
		$snippet = Searcher::HIGHLIGHT_PRE_MARKER . 'catapult' . Searcher::HIGHLIGHT_POST_MARKER;
		$html = self::format( self::response( [ 'result' => [ 'hits' => [ 'hits' => [
			self::response()['result']['hits']['hits'][0] + [
				'highlight' => [ 'text' => [ $snippet ] ],
			],
		] ] ] ] ) );

		$this->assertStringContainsString( $snippet, $html );
	}

	/** A benign response still renders the heading text it always did. */
	public function testBenignHeadingRendersUnchanged() {
		$html = self::format( self::response() );

		$this->assertStringContainsString(
			"<h2>full_text search for 'catapult' on my_wiki_content/_search</h2>",
			$html
		);
	}
}
