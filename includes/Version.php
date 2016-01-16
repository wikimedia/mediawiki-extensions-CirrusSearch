<?php

namespace CirrusSearch;
use \Status;

/**
 * Fetch the Elasticsearch version
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
class Version extends ElasticsearchIntermediary {
	/**
	 * Constructor
	 * @param Connection $conn
	 */
	public function __construct( Connection $conn ) {
		parent::__construct( $conn, null, 0 );
	}

	/**
	 * Get the version of Elasticsearch with which we're communicating.
	 *
	 * @return Status<string> version number as a string
	 */
	public function get() {
		global $wgMemc, $wgCirrusSearchClientSideSearchTimeout;

		$mcKey = wfMemcKey( 'CirrusSearch', 'Elasticsearch', 'version' );
		$result = $wgMemc->get( $mcKey );
		if ( !$result ) {
			try {
				$this->start( 'fetching elasticsearch version',
					array( 'queryType' => 'version' ) );
				// If this times out the cluster is in really bad shape but we should still
				// check it.
				$this->connection->setTimeout( $wgCirrusSearchClientSideSearchTimeout[ 'default' ] );
				$result = $this->connection->getClient()->request( '' );
				$this->success();
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				return $this->failure( $e );
			}
			$result = $result->getData();
			$result = $result[ 'version' ][ 'number' ];
			$wgMemc->set( $mcKey, $result, 3600 * 12 );
		}

		return Status::newGood( $result );
	}
}
