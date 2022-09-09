<?php

namespace CirrusSearch\Maintenance;

use Elastica\Client;
use Elasticsearch\Endpoints;
use MediaWiki\Extension\Elastica\MWElasticUtils;

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
	 * @var Printer
	 */
	private $out;

	/**
	 * @param Client $client
	 * @param Printer $out
	 */
	public function __construct( Client $client, Printer $out ) {
		$this->client = $client;
		$this->out = $out;
	}

	public function checkElasticsearchVersion() {
		$this->outputIndented( 'Fetching Elasticsearch version...' );
		$response = $this->client->request( '' );
		if ( !$response->isOK() ) {
			$this->fatalError( "Cannot fetch elasticsearch version: "
				. $response->getError() );
		}
		$result = $response->getData();
		if ( !isset( $result['version']['number'] ) ) {
			$this->fatalError( 'unable to determine, aborting.' );
		}
		$result = $result[ 'version' ][ 'number' ];
		$this->output( "$result..." );
		if ( strpos( $result, '7.10' ) !== 0 ) {
			if ( strpos( $result, '6.8' ) == 0 ) {
				$this->output( "partially supported\n" );
				$this->error( "You use a version of elasticsearch that is partially supported, you should upgrade to 7.10.x\n" );
			} else {
				$this->output( "Not supported!\n" );
				$this->fatalError( "Only Elasticsearch 7.10.x is supported.  Your version: $result." );
			}
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
					"but the one of them currently active. Here is the list: " . implode( ',', $found ) );
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
		$response = $this->client->requestEndpoint( ( new Endpoints\Indices\Get() )
			->setIndex( $typeName . '*' )
			->setParams( [ 'include_type_name' => 'false' ] ) );
		if ( !$response->isOK() ) {
			$this->fatalError( "Cannot fetch index names for $typeName: "
				. $response->getError() );
		}
		return array_keys( $response->getData() );
	}

	/**
	 * @param string $what generally plugins or modules
	 * @return string[] list of modules or plugins
	 */
	private function scanModulesOrPlugins( $what ) {
		$response = $this->client->request( '_nodes' );
		if ( !$response->isOK() ) {
			$this->fatalError( "Cannot fetch node state from cluster: "
				. $response->getError() );
		}
		$result = $response->getData();
		$availables = [];
		$first = true;
		foreach ( array_values( $result[ 'nodes' ] ) as $node ) {
			// The plugins section may not exist, default to [] when not found.
			$plugins = array_column( $node[$what] ?? [], 'name' );
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
		if ( $availablePlugins === [] ) {
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
		if ( $availableModules === [] ) {
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
	 * @param mixed|null $channel
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
	 * @return never
	 */
	private function fatalError( $message, $exitCode = 1 ) {
		if ( $this->out ) {
			$this->out->error( $message );
		}
		exit( $exitCode );
	}

	/**
	 * Wait for the index to go green
	 *
	 * @param string $indexName
	 * @param int $timeout
	 * @return bool true if the index is green false otherwise.
	 */
	public function waitForGreen( $indexName, $timeout ) {
		$statuses = MWElasticUtils::waitForGreen(
			$this->client, $indexName, $timeout );
		foreach ( $statuses as $message ) {
			$this->outputIndented( $message . "\n" );
		}
		return $statuses->getReturn();
	}

	/**
	 * Checks if this is an index (not an alias)
	 * @param string $indexName
	 * @return bool true if this is an index, false if it's an alias or if unknown
	 */
	public function isIndex( $indexName ) {
		// We must emit a HEAD request before calling the _alias
		// as it may return an error if the index/alias is missing
		if ( !$this->client->getIndex( $indexName )->exists() ) {
			return false;
		}

		$response = $this->client->request( $indexName . '/_alias' );
		if ( !$response->isOK() ) {
			$this->fatalError( "Cannot determine if $indexName is an index: "
				. $response->getError() );
		}
		// Only index names are listed as top level keys So if
		// HEAD /$indexName returns HTTP 200 but $indexName is
		// not a top level json key then it's an alias
		return isset( $response->getData()[$indexName] );
	}

	/**
	 * Return a list of index names that points to $aliasName
	 * @param string $aliasName
	 * @return string[] index names
	 */
	public function getIndicesWithAlias( $aliasName ) {
		// We must emit a HEAD request before calling the _alias
		// as it may return an error if the index/alias is missing
		if ( !$this->client->getIndex( $aliasName )->exists() ) {
			return [];
		}
		$response = $this->client->request( $aliasName . '/_alias' );
		if ( !$response->isOK() ) {
			$this->fatalError( "Cannot fetch indices with alias $aliasName: "
				. $response->getError() );
		}
		return array_keys( $response->getData() );
	}
}
