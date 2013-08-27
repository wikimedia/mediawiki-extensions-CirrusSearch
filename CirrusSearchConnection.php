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
class CirrusSearchConnection {
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
	 * Singleton instance of the client
	 * @var \Elastica\Client
	 */
	private static $client = null;

	/**
	 * @return \Elastica\Client|null
	 */
	public static function getClient() {
		if ( self::$client != null ) {
			return self::$client;
		}
		global $wgCirrusSearchServers;

		// Setup the Elastica endpoints
		$servers = array();
		foreach ( $wgCirrusSearchServers as $server ) {
			$servers[] = array('host' => $server);
		}
		self::$client = new \Elastica\Client( array(
			'servers' => $servers
		) );
		return self::$client;
	}

	/**
	 * Fetch the Elastica Index.
	 * @param mixed $type type of index (content or general or false to get all)
	 * @param mixed $identifier if specified get the named identified version of the index
	 * @return \Elastica\Index
	 */
	public static function getIndex( $type = false, $identifier = false ) {
		return self::getClient()->getIndex( self::getIndexName( $type, $identifier ) );
	}

	/**
	 * Get the name of the index.
	 * @param mixed $type type of index (content or general or false to get all)
	 * @param mixed $identifier if specified get the named identifier of the index
	 * @return string name index should have considering $identifier
	 */
	public static function getIndexName( $type = false, $identifier = false ) {
		$name = wfWikiId();
		if ( $type ) {
			$name = $name . '_' . $type;
		}
		if ( $identifier ) {
			$name = $name . '_' . $identifier;
		}
		return $name;
	}

	/**
	 * Fetch the Elastica Type for pages.
	 * @param mixed $type type of index (content or general or false to get all)
	 * @return \Elastica\Type
	 */
	public static function getPageType( $type = false ) {
		return self::getIndex( $type )->getType( self::PAGE_TYPE_NAME );
	}
}
