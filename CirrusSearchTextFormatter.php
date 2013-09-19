<?php
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
class CirrusSearchTextFormatter extends HtmlFormatter {
	/**
	 * Get formatted text from a Title & ParserOutput
	 *
	 * @param Title $t
	 * @param ParserOutput $po
	 * @return formatted text from the title or null if we can't build the parser output
	 */
	public static function formatWikitext( Title $t, ParserOutput $po = null ) {
		if ( !$po ) {
			$article = new Article( $t, 0 );
			$po = $article->getParserOutput();
		}
		if ( !$po ) {
			wfLogWarning( "CirrusSearch couldn't get parser output for $t.  Returning null text which should be skipped." );
			return null;
		}
		$po->setEditSectionTokens( false );
		$formatter = new self( $po->getText() );
		$formatter->remove( array( 'audio', 'video', '#toc' ) );
		$formatter->filterContent();
		return $formatter->getText();
	}
}
