<?php

namespace CirrusSearch;

/**
 * Retrieve Interwiki information.
 * Designed to support CirrusSearch usecase:
 * - getSisterProjectPrefixes(): same lang different project
 * - getSameProjectWikiByLang(): same project different lang
 * - getInterwikiPrefix(): retrieve the interwiki prefix from a wikiId
 */
interface InterwikiResolver {
	/** @const string service name */
	const SERVICE = 'CirrusSearchInterwikiresolver';

	/**
	 * @return string[] of wikiIds indexed by interwiki prefix
	 */
	public function getSisterProjectPrefixes();

	/**
	 * @param string $wikiId
	 * @return string|null the interwiki identified for this $wikiId or null if none found
	 */
	public function getInterwikiPrefix( $wikiId );

	/**
	 * Determine the proper interwiki_prefix <=> wikiId pair for a given language code.
	 * Most the time the language code is equals to interwiki prefix but in
	 * some rarer cases it's not true. Always use the interwiki prefix returned by this function
	 * to generate crosslanguage interwiki links.
	 *
	 * @param string $lang
	 * @return string[] a two elt array ['wikiId', 'iwPrefix'] or [] if none found
	 */
	public function getSameProjectWikiByLang( $lang );
}
