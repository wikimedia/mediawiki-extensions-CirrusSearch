<?php
/**
 * Gets sanitized article text from titles.
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
class CirrusSearchTextSanitizer {
	/**
	 * Regex to remove text we don't want to search but that isn't already
	 * removed when stripping HTML or the toc.
	 */
	const SANITIZE = '/
		<video .*?<\/video>  # remove the sorry, not supported message
	/x';

	/**
	 * Get sanitized text from a Title.
	 * @param Title $t
	 * @return sanitized text from the title
	 */
	public static function getSantizedTextFromTitle( Title $t ) {
		$article = new Article( $t, 0 );
		$parserOutput = $article->getParserOutput();
		$parserOutput->setEditSectionTokens( false );       // Don't add edit tokens
		$text = $parserOutput->getText();                   // Fetch the page
		$text = self::stripToc( $text );                   // Strip the table of contents
		$text = preg_replace( self::SANITIZE, '', $text );  // Strip other non-searchable text
		return $text;
	}

	/**
	 * Strip the table of contents from a rendered page.  Note that we don't use
	 * regexes for this because we're removing whole lines.
	 *
	 * @var $text string the rendered page
	 * @return string the rendered page without the toc
	 */
	private static function stripToc( $text ) {
		$t = explode( "\n", $text );
		$t = array_filter( $t, function( $line ) {
			return strpos( $line, 'id="toctitle"' ) === false &&  // Strip the beginning of the toc
				 strpos( $line, 'class="tocnumber"') === false && // And any lines with toc numbers
				 strpos( $line, 'class="toctext"') === false;     // And finally lines with toc text
		});
		return implode( "\n", $t );
	}
}
