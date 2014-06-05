<?php

namespace CirrusSearch\BuildDocument;
use \HtmlFormatter;
use \ParserOutput;
use \Sanitizer;

/**
 * Adds fields to the document that require article text.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class PageTextBuilder extends ParseBuilder {
	public function __construct( $doc, $content, $parserOutput ) {
		parent::__construct( $doc, null, $content, $parserOutput );
	}

	public function build() {
		list( $text, $auxiliary ) = $this->buildTextToIndex();
		$this->doc->add( 'text', $text );
		$this->doc->add( 'auxiliary_text', $auxiliary );
		$this->doc->add( 'text_bytes', strlen( $text ) );
		$this->doc->add( 'source_text', $this->buildSourceTextToIndex() );

		return $this->doc;
	}

	/**
	 * Fetch text to index. If $content is wikitext then render and strip things from it.
	 * Otherwise delegate to the $content itself. Then trim and sanitize the result.
	 */
	private function buildTextToIndex() {
		switch ( $this->content->getModel() ) {
			case CONTENT_MODEL_WIKITEXT:
				return $this->formatWikitext( $this->parserOutput );
			default:
				$text = trim( Sanitizer::stripAllTags( $this->content->getTextForSearchIndex() ) );
				return array( $text, array() );
		}

		return $text;
	}

	/**
	 * Some sorts of content (basically wikitext) have expanded and
	 * unexpanded forms.
	 */
	private function buildSourceTextToIndex() {
		switch ( $this->content->getModel() ) {
			case CONTENT_MODEL_WIKITEXT:
				return $this->content->getTextForSearchIndex();
			default:
				return null;
		}
	}

	/**
	 * Get text to index from a ParserOutput assuming the content was wikitext.
	 *
	 * @param ParserOutput $parserOutput The parsed wikitext's parser output
	 * @return formatted text from the provided parser output
	 */
	private function formatWikitext( ParserOutput $parserOutput ) {
		$parserOutput->setEditSectionTokens( false );
		$formatter = new HtmlFormatter( $parserOutput->getText() );

		// Strip elements from the page that we never want in the search text.
		$formatter->remove( array(
			'audio', 'video',       // "it looks like you don't have javascript enabled..." do not need to index
			'#toc',                 // Already indexed as part of the headings.  No need.
			'sup.reference',        // The [1] for references
			'.mw-cite-backlink',    // The ↑ next to refenences in the references section
			'h1', 'h2', 'h3',       // Headings are already indexed in their own field.
			'h5', 'h6', 'h4',
		) );
		$formatter->filterContent();

		// Strip elements from the page that are auxiliary text.  These will still be
		// searched but matches will be ranked lower and non-auxiliary matches will be
		// prefered in highlighting.
		$formatter->remove( array(
			'.thumbcaption',        // Thumbnail captions aren't really part of the text proper
			'table',                // Neither are tables
			'.rellink',             // Common style for "See also:".
			'.dablink',             // Common style for calling out helpful links at the top of the article.
			'.searchaux',           // New class users can use to mark stuff as auxiliary to searches.
		) );
		$auxiliaryElements = $formatter->filterContent();
		$text = trim( Sanitizer::stripAllTags( $formatter->getText() ) );
		$auxiliary = array();
		foreach ( $auxiliaryElements as $auxiliaryElement ) {
			$auxiliary[] = trim( Sanitizer::stripAllTags( $formatter->getText( $auxiliaryElement ) ) );
		}

		return array( $text, $auxiliary );
	}

	/**
	 * Get the unicode paragraph separator character.
	 */
	private function paragraphSeparator() {
		static $paragraphSeparator;
		if ( $paragraphSeparator === null ) {
			$paragraphSeparator = json_decode('"\u2029"');
		}
		return $paragraphSeparator;
	}
}
