<?php

namespace CirrusSearch;
use \HtmlFormatter;
use \ParserOutput;
use \Sanitizer;

/**
 * Gets formatted article text from titles.
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
class TextFormatter extends HtmlFormatter {
	/**
	 * Get text to index from a ParserOutput assuming the content was wikitext.
	 *
	 * @param ParserOutput $po
	 * @return formatted text from the provided parser output
	 */
	public static function formatWikitext( ParserOutput $po ) {
		$po->setEditSectionTokens( false );
		$formatter = new self( $po->getText() );
		$formatter->remove( array( 'audio', 'video', '#toc' ) );
		$formatter->filterContent();
		return trim( Sanitizer::stripAllTags( $formatter->getText() ) );
	}
}
