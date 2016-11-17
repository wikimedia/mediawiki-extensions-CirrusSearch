<?php

namespace CirrusSearch;

class EmptyInterwikiResolver implements InterwikiResolver {
	/**
	 * @return string[] of wikiIds indexed by interwiki prefix
	 */
	public function getSisterProjectPrefixes() {
		return [];
	}

	/**
	 * @return string|null the interwiki identified for this $wikiId or null if none found
	 */
	public function getInterwikiPrefix( $wikiId ) {
		return null;
	}

	/**
	 * @return string[] a single elt array [ 'iw_prefix' => 'wikiId' ] or [] if none found
	 */
	public function getSameProjectWikiByLang( $lang ) {
		return [];
	}
}
