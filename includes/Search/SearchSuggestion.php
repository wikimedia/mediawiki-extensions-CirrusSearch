<?php

namespace CirrusSearch\Search;

use Title;

/**
 * Search suggestion
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
 *
 */

/**
 * A search suggestion
 *
 */
class SearchSuggestion {
	/**
	 * @var string the suggestion
	 */
	private $text;

	/**
	 * @var string the suggestion URL
	 */
	private $url;

	/**
	 * @var Title|null the suggested title
	 */
	private $suggestedTitle;

	/**
	 * NOTE: even if suggestedTitle is a redirect suggestedTitleID
	 * is the ID of the target page.
	 * @var int|null the suggested title ID
	 */
	private $suggestedTitleID;

	/**
	 * @var float|null The suggestion score
	 */
	private $score;

	/**
	 * Construct a new suggestion
	 * @param string $text|null the suggestion text
	 * @param string $url|null the suggestion URL
	 * @param float|0 the suggestion score
	 * @param Title|null $suggestedTitle the suggested title
	 * @param int|null the suggested title ID
	 */
	public function __construct( $text = null, $url = null, $score = 0, Title $suggestedTitle = null, $suggestedTitleID = null ) {
		$this->text = $text;
		$this->url = $url;
		$this->score = $score;
		$this->suggestedTitle = $suggestedTitle;
		$this->suggestedTitleID = $suggestedTitleID;
	}

	/**
	 * The suggestion text
	 * @return string
	 */
	public function getText() {
		return $this->text;
	}

	/**
	 * Set the suggestion text
	 * @param string $text
	 */
	public function setText( $text ) {
		$this->text = $text;
	}

	/**
	 * Title object in the case this suggestion is based on a title.
	 * May return null if the suggestion is not a Title.
	 * @return Title|null
	 */
	public function getSuggestedTitle() {
		return $this->suggestedTitle;
	}

	/**
	 * Set the suggested title
	 * @param Title|null $title
	 * @param boolean|false $generateURL set to true to generate the URL based on this Title
	 */
	public function setSuggestedTitle( Title $title = null, $generateURL = false ) {
		$this->suggestedTitle = $title;
		if ( $title !== null && $generateURL ) {
			$this->url = wfExpandUrl( $title->getFullURL(), PROTO_CURRENT );
		}
	}

	/**
	 * Title ID in the case this suggestion is based on a title.
	 * May return null if the suggestion is not a Title.
	 * @return int|null
	 */
	public function getSuggestedTitleID() {
		return $this->suggestedTitleID;
	}

	/**
	 * Set the suggested title ID
	 * @param int|null $suggestedTitleID
	 */
	public function setSuggestedTitleID( $suggestedTitleID = null ) {
		$this->suggestedTitleID = $suggestedTitleID;
	}

	/**
	 * Suggestion score
	 * @return float Suggestion score
	 */
	public function getScore() {
		return $this->score;
	}

	/**
	 * Set the suggestion score
	 * @param float $score
	 */
	public function setScore( $score ) {
		$this->score = $score;
	}

	/**
	 * Suggestion URL, can be the link to the Title or maybe in the
	 * future a link to the search results for this search suggestion.
	 * @return string Suggestion URL
	 */
	public function getURL() {
		return $this->url;
	}

	/**
	 * Set the suggestion URL
	 * @param string $url
	 */
	public function setURL( $url ) {
		$this->url = $url;
	}
}
