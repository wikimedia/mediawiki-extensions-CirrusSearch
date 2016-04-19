<?php

namespace CirrusSearch\Maintenance;

use Elastica\Client;

/**
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
class ConfigUtils {
	/**
	 * @var Client
	 */
	private $client;

	/**
	 * @var Maintenance
	 */
	private $out;

	/**
	 * @param Client $client
	 * @param Maintenance $out
	 */
	public function __construct( Client $client, Maintenance $out ) {
		$this->client = $client;
		$this->out = $out;
	}

	public function checkElasticsearchVersion() {
		$this->outputIndented( 'Fetching Elasticsearch version...' );
		$result = $this->client->request( '' );
		$result = $result->getData();
		if ( !isset( $result['version']['number'] ) ) {
			$this->error( 'unable to determine, aborting.', 1 );
		}
		$result = $result[ 'version' ][ 'number' ];
		$this->output( "$result..." );
		if ( !preg_match( '/^1./', $result ) ) {
			$this->output( "Not supported!\n" );
			$this->error( "Only Elasticsearch 1.x is supported.  Your version: $result.", 1 );
		} else {
			$this->output( "ok\n" );
		}
	}

	/**
	 * Pick the index identifier from the provided command line option.
	 *
	 * @param string $option command line option
	 *          'now'        => current time
	 *          'current'    => if there is just one index for this type then use its identifier
	 *          other string => that string back
	 * @param string $typeName
	 * @return string index identifier to use
	 */
	public function pickIndexIdentifierFromOption( $option, $typeName ) {
		if ( $option === 'now' ) {
			$identifier = strval( time() );
			$this->outputIndented( "Setting index identifier...${typeName}_${identifier}\n" );
			return $identifier;
		}
		if ( $option === 'current' ) {
			$this->outputIndented( 'Inferring index identifier...' );
			$found = $this->getAllIndicesByType( $typeName );
			if ( count( $found ) > 1 ) {
				$this->output( "error\n" );
				$this->error( "Looks like the index has more than one identifier. You should delete all\n" .
					"but the one of them currently active. Here is the list: " .  implode( $found, ',' ), 1 );
			}
			if ( $found ) {
				$identifier = substr( $found[0], strlen( $typeName ) + 1 );
				if ( !$identifier ) {
					// This happens if there is an index named what the alias should be named.
					// If the script is run with --startOver it should nuke it.
					$identifier = 'first';
				}
			} else {
				$identifier = 'first';
			}
			$this->output( "${typeName}_${identifier}\n");
			return $identifier;
		}
		return $option;
	}

	/**
	 * Scan the indices and return the ones that match the
	 * type $typeName
	 *
	 * @param string $typeName the type to filter with
	 * @return string[] the list of indices
	 */
	public function getAllIndicesByType( $typeName ) {
		$found = array();
		foreach ( $this->client->getStatus()->getIndexNames() as $name ) {
			if ( substr( $name, 0, strlen( $typeName ) ) === $typeName ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * @param string[] $bannedPlugins
	 * @return string[]
	 */
	public function scanAvailablePlugins( array $bannedPlugins = array() ) {
		$this->outputIndented( "Scanning available plugins..." );
		$result = $this->client->request( '_nodes' );
		$result = $result->getData();
		$availablePlugins = array();
		$first = true;
		foreach ( array_values( $result[ 'nodes' ] ) as $node ) {
			$plugins = array();
			foreach ( $node[ 'plugins' ] as $plugin ) {
				$plugins[] = $plugin[ 'name' ];
			}
			if ( $first ) {
				$availablePlugins = $plugins;
				$first = false;
			} else {
				$availablePlugins = array_intersect( $availablePlugins, $plugins );
			}
		}
		if ( count( $availablePlugins ) === 0 ) {
			$this->output( 'none' );
		}
		$this->output( "\n" );
		if ( count( $bannedPlugins ) ) {
			$availablePlugins = array_diff( $availablePlugins, $bannedPlugins );
		}
		foreach ( array_chunk( $availablePlugins, 5 ) as $pluginChunk ) {
			$plugins = implode( ', ', $pluginChunk );
			$this->outputIndented( "\t$plugins\n" );
		}

		return $availablePlugins;
	}

	// @todo: bring below options together in some abstract class where Validator & Reindexer also extend from

	/**
	 * @param string $message
	 * @param mixed $channel
	 */
	protected function output( $message, $channel = null ) {
		if ( $this->out ) {
			$this->out->output( $message, $channel );
		}
	}

	/**
	 * @param string $message
	 */
	protected function outputIndented( $message ) {
		if ( $this->out ) {
			$this->out->outputIndented( $message );
		}
	}

	/**
	 * @param string $message
	 * @param int $die
	 */
	private function error( $message, $die = 0 ) {
		// @todo: I'll want to get rid of this method, but this patch will be big enough already
		// @todo: I'll probably want to throw exceptions and/or return Status objects instead, later

		if ( $this->out ) {
			$this->out->error( $message, $die );
		}

		$die = intval( $die );
		if ( $die > 0 ) {
			die( $die );
		}
	}

	/**
	 * Get index health
	 *
	 * @param string $indexName
	 * @return array the index health status
	 */
	public function getIndexHealth( $indexName ) {
		$path = "_cluster/health/$indexName";
		$response = $this->client->request( $path );
		if ( $response->hasError() ) {
			throw new \Exception( "Error while fetching index health status: ". $response->getError() );
		}
		return $response->getData();
	}

	/**
	 * Wait for the index to go green
	 *
	 * @param string $indexName
	 * @param int $timeout
	 * @return boolean true if the index is green false otherwise.
	 */
	public function waitForGreen( $indexName, $timeout ) {
		$startTime = time();
		while( ( $startTime + $timeout ) > time() ) {
			try {
				$response = $this->getIndexHealth( $indexName );
				$status = isset ( $response['status'] ) ? $response['status'] : 'unknown';
				if ( $status == 'green' ) {
					$this->outputIndented( "\tGreen!\n" );
					return true;
				}
				$this->outputIndented( "\tIndex is $status retrying...\n" );
				sleep( 5 );
			} catch( \Exception $e ) {
				$this->output( "Error while waiting for green ({$e->getMessage()}), retrying...\n" );
			}
		}
		return false;
	}
}
