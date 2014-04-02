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
		$text = $this->buildTextToIndex();
		$this->doc->add( 'text', $text );
		$this->doc->add( 'text_bytes', strlen( $text ) );
		return $this->doc;
	}

	/**
	 * Fetch text to index. If $content is wikitext then render and strip things from it.
	 * Otherwise delegate to the $content itself. Then trim and sanitize the result.
	 */
	private function buildTextToIndex() {
		switch ( $this->content->getModel() ) {
			case CONTENT_MODEL_WIKITEXT:
				$text = $this->formatWikitext( $this->parserOutput );
				break;
			default:
				$text = $this->content->getTextForSearchIndex();
		}

		return trim( Sanitizer::stripAllTags( $text ) );
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
		$formatter->remove( array( 'audio', 'video', '#toc', '.thumbcaption' ) );
		$formatter->filterContent();
		return $formatter->getText();
	}

}
