<?php
/**
 * SearchEngine implementation for CirrusSearch.  Delegates to
 * CirrusSearchSearcher for searches and CirrusSearchUpdater for updates.
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
class CirrusSearch extends SearchEngine {
	/**
	 * @param string $term
	 * @return CirrusSearchResultSet|null|SearchResultSet|Status
	 */
	public function searchText( $term ) {
		return CirrusSearchSearcher::searchText( $term, $this->offset, $this->limit,
			$this->namespaces, $this->showRedirects );
	}

	public function update( $id, $title, $text ) {
		CirrusSearchUpdater::updateFromTitleAndText( $id, $title, $text );
	}

	public function updateTitle( $id, $title ) {
		CirrusSearchUpdater::updateFromTitle( $title );
	}

	public function delete( $id, $title ) {
		CirrusSearchUpdater::deletePages( array( $id ) );
	}

	public function getTextFromContent( Title $t, Content $c = null ) {
		$text = parent::getTextFromContent( $t, $c );
		if( $c ) {
			switch ( $c->getModel() ) {
				case CONTENT_MODEL_WIKITEXT:
					$text = CirrusSearchTextSanitizer::getSantizedTextFromTitle( $t );
					break;
				default:
					$text = SearchUpdate::updateText( $text );
					break;
			}
		}
		return $text;
	}

	public function textAlreadyUpdatedForIndex() {
		return true;
	}
}
