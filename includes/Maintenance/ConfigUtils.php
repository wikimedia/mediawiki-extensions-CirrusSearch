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
			$this->fatalError( 'unable to determine, aborting.' );
		}
		$result = $result[ 'version' ][ 'number' ];
		$this->output( "$result..." );
		if ( !preg_match( '/^5./', $result ) ) {
			$this->output( "Not supported!\n" );
			$this->fatalError( "Only Elasticsearch 5.x is supported.  Your version: $result." );
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
				$this->fatalError( "Looks like the index has more than one identifier. You should delete all\n" .
					"but the one of them currently active. Here is the list: " .  implode( ',', $found ) );
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
			$this->output( "${typeName}_${identifier}\n" );
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
		$found = null;
		$response = $this->client->request( $typeName . '*' );
		if ( $response->isOK() ) {
			$found = array_keys( $response->getData() );
		} else {
			$this->fatalError( "Cannot fetch index names for $typeName: "
				. $response->getError() );
		}
		return $found;
	}

	/**
	 * @param string $what generally plugins or modules
	 * @return string[] list of modules or plugins
	 */
	private function scanModulesOrPlugins( $what ) {
		$result = $this->client->request( '_nodes' );
		$result = $result->getData();
		$availables = [];
		$first = true;
		foreach ( array_values( $result[ 'nodes' ] ) as $node ) {
			$plugins = [];
			foreach ( $node[ $what ] as $plugin ) {
				$plugins[] = $plugin[ 'name' ];
			}
			if ( $first ) {
				$availables = $plugins;
				$first = false;
			} else {
				$availables = array_intersect( $availables, $plugins );
			}
		}
		return $availables;
	}

	/**
	 * @param string[] $bannedPlugins
	 * @return string[]
	 */
	public function scanAvailablePlugins( array $bannedPlugins = [] ) {
		$this->outputIndented( "Scanning available plugins..." );
		$availablePlugins = $this->scanModulesOrPlugins( 'plugins' );
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

	/**
	 * @return string[]
	 */
	public function scanAvailableModules() {
		$this->outputIndented( "Scanning available modules..." );
		$availableModules = $this->scanModulesOrPlugins( 'modules' );
		if ( count( $availableModules ) === 0 ) {
			$this->output( 'none' );
		}
		$this->output( "\n" );
		foreach ( array_chunk( $availableModules, 5 ) as $moduleChunk ) {
			$modules = implode( ', ', $moduleChunk );
			$this->outputIndented( "\t$modules\n" );
		}

		return $availableModules;
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
	 */
	private function error( $message ) {
		if ( $this->out ) {
			$this->out->error( $message );
		}
	}

	/**
	 * @param string $message
	 * @param int $exitCode
	 */
	private function fatalError( $message, $exitCode = 1 ) {
		if ( $this->out ) {
			$this->out->error( $message );
		}
		exit( $exitCode );
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
	 * @return bool true if the index is green false otherwise.
	 */
	public function waitForGreen( $indexName, $timeout ) {
		$startTime = time();
		while ( ( $startTime + $timeout ) > time() ) {
			try {
				$response = $this->getIndexHealth( $indexName );
				$status = isset( $response['status'] ) ? $response['status'] : 'unknown';
				if ( $status == 'green' ) {
					$this->outputIndented( "\tGreen!\n" );
					return true;
				}
				$this->outputIndented( "\tIndex is $status retrying...\n" );
				sleep( 5 );
			} catch ( \Exception $e ) {
				$this->output( "Error while waiting for green ({$e->getMessage()}), retrying...\n" );
			}
		}
		return false;
	}

	/**
	 * Checks if this is an index (not an alias)
	 * @param string $indexName
	 * @return bool true if this is an index, false if it's an alias or if unknown
	 */
	public function isIndex( $indexName ) {
		// We must emit a HEAD request before calling the _aliases
		// as it may return an error if the index/alias is missing
		if ( !$this->client->getIndex( $indexName )->exists() ) {
			return false;
		}

		$response = $this->client->request( $indexName . '/_aliases' );
		if ( $response->isOK() ) {
			// Only index names are listed as top level keys So if
			// HEAD /$indexName returns HTTP 200 but $indexName is
			// not a top level json key then it's an alias
			return isset( $response->getData()[$indexName] );
		} else {
			$this->fatalError( "Cannot determine if $indexName is an index: "
				. $response->getError() );
		}
		return false;
	}

	/**
	 * Return a list of index names that points to $aliasName
	 * @param string $aliasName
	 * @return string[] index names
	 */
	public function getIndicesWithAlias( $aliasName ) {
		// We must emit a HEAD request before calling the _aliases
		// as it may return an error if the index/alias is missing
		if ( !$this->client->getIndex( $aliasName )->exists() ) {
			return [];
		}
		$response = $this->client->request( $aliasName . '/_aliases' );
		if ( $response->isOK() ) {
			return array_keys( $response->getData() );
		} else {
			$this->fatalError( "Cannot fetch indices with alias $aliasName: "
				. $response->getError() );
		}
		return [];
	}
}
