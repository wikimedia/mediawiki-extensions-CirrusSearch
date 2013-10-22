<?php
/**
 * Forms and caches connection to Elasticsearch as well as client objects
 * that contain connection information like \Elastica\Index and \Elastica\Type.
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
class CirrusSearchConnection extends ElasticaConnection {
	/**
	 * Name of the index that holds content articles.
	 * @var string
	 */
	const CONTENT_INDEX_TYPE = 'content';
	
	/**
	 * Name of the index that holds non-content articles.
	 * @var string
	 */
	const GENERAL_INDEX_TYPE = 'general';

	/**
	 * Name of the page type.
	 * @var string
	 */
	const PAGE_TYPE_NAME = 'page';

	/**
	 * @return array(string)
	 */
	public function getServerList() {
		global $wgCirrusSearchServers;
		return $wgCirrusSearchServers;
	}

	/**
	 * How many times can we attempt to connect per host?
	 *
	 * @return int
	 */
	public function getMaxConnectionAttempts() {
		global $wgCirrusSearchConnectionAttempts;
		return $wgCirrusSearchConnectionAttempts;
	}

	/**
	 * Fetch the Elastica Type for pages.
	 * @param mixed $type type of index (content or general or false to get all)
	 * @param mixed $name basename of index, defaults to wfWikiId()
	 * @return \Elastica\Type
	 */
	public static function getPageType( $type = false, $name = false ) {
		$name = $name ?: wfWikiId();
		return self::getIndex( $name, $type )->getType( self::PAGE_TYPE_NAME );
	}
}
