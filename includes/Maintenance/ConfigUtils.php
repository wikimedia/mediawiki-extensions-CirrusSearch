<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use Elastica\Client;
use Elastica\Exception\ResponseException;
use Elastica\Index;
use Elasticsearch\Endpoints;
use LogicException;
use MediaWiki\Extension\Elastica\MWElasticUtils;
use MediaWiki\Status\Status;
use RuntimeException;

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

	public function __construct( Client $client, Printer $out ) {
		$this->client = $client;
		$this->out = $out;
	}

	public function checkElasticsearchVersion(): Status {
		$this->outputIndented( 'Fetching server version...' );
		$response = $this->client->request( '' );
		if ( !$response->isOK() ) {
			return Status::newFatal( "Cannot fetch elasticsearch version: "
				. $response->getError() );
		}
		$banner = $response->getData();
		if ( !isset( $banner['version']['number'] ) ) {
			return Status::newFatal( 'unable to determine, aborting.' );
		}
		$distribution = $banner['version']['distribution'] ?? 'elasticsearch';
		$version = $banner['version']['number'];
		$this->output( "$distribution $version..." );

		$required = $distribution === 'opensearch' ? '1.3' : '7.10';
		if ( strpos( $version, $required ) !== 0 ) {
			$this->output( "Not supported!\n" );
			return Status::newFatal(
				"Only OpenSearch 1.3.x is supported; Elasticsearch 7.10.x is now deprecated "
				. " and support will be removed soon.\n  Your version: $distribution $version." );
		}
		if ( $distribution === 'elasticsearch' ) {
			$this->output( "deprecated.\n" );
			$this->outputIndented(
				"*** ElasticSearch support is deprecated and will be End-of-Life in the next "
				. "release. Upgrading to OpenSearch will be required. ***\n"
			);
		} else {
			$this->output( "ok\n" );
		}
		return Status::newGood();
	}

	/**
	 * Pick the index identifier from the provided command line option.
	 *
	 * @param string $option command line option
	 *          'now'        => current time
	 *          'current'    => if there is just one index for this type then use its identifier
	 *          other string => that string back
	 * @param string $typeName
	 * @return Status<string> holds string index identifier to use
	 */
	public function pickIndexIdentifierFromOption( $option, $typeName ): Status {
		if ( $option === 'now' ) {
			$identifier = strval( time() );
			$this->outputIndented( "Setting index identifier...{$typeName}_{$identifier}\n" );
			return Status::newGood( $identifier );
		}
		if ( $option === 'current' ) {
			$this->outputIndented( 'Inferring index identifier...' );
			$foundStatus = $this->getAllIndicesByType( $typeName );
			if ( !$foundStatus->isGood() ) {
				return $foundStatus;
			}
			$found = $foundStatus->getValue();

			if ( count( $found ) > 1 ) {
				$this->output( "error\n" );
				return Status::newFatal(
					"Looks like the index has more than one identifier. You should delete all\n" .
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
			$this->output( "{$typeName}_{$identifier}\n" );
			return Status::newGood( $identifier );
		}
		return Status::newGood( $option );
	}

	/**
	 * Scan the indices and return the ones that match the
	 * type $typeName
	 *
	 * @param string $typeName the type to filter with
	 * @param bool $excludeAltIndices exclude alternative indices
	 * @return Status holds string[] with list of indices
	 */
	public function getAllIndicesByType( $typeName, bool $excludeAltIndices = true ): Status {
		$indexQuery = "$typeName*";
		if ( $excludeAltIndices ) {
			$altIndexSuffix = Connection::ALT_SUFFIX;
			$indexQuery .= ",-{$typeName}_{$altIndexSuffix}_*";
		}

		return $this->listIndices( $indexQuery );
	}

	/**
	 * Scan the indices and return the ones that match the
	 * type $typeName and are alternative indices
	 *
	 * @param string $typeName the type to filter with
	 * @return Status holds string[] with list of indices
	 */
	public function getAllAlternativeIndicesByType( $typeName ): Status {
		$altIndexSuffix = Connection::ALT_SUFFIX;
		return $this->listIndices( "{$typeName}_{$altIndexSuffix}_*" );
	}

	/**
	 * @param string $what generally plugins or modules
	 * @return Status holds string[] list of modules or plugins
	 */
	private function scanModulesOrPlugins( $what ): Status {
		$response = $this->client->request( '_nodes' );
		if ( !$response->isOK() ) {
			return Status::newFatal( "Cannot fetch node state from cluster: "
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
		return Status::newGood( $availables );
	}

	/**
	 * @param string[] $bannedPlugins
	 * @return Status holds string[]
	 */
	public function scanAvailablePlugins( array $bannedPlugins = [] ): Status {
		$this->outputIndented( "Scanning available plugins..." );
		$availablePluginsStatus = $this->scanModulesOrPlugins( 'plugins' );
		if ( !$availablePluginsStatus->isGood() ) {
			return $availablePluginsStatus;
		}
		$availablePlugins = $availablePluginsStatus->getValue();

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

		return Status::newGood( $availablePlugins );
	}

	/**
	 * @return Status holds string[]
	 */
	public function scanAvailableModules(): Status {
		$this->outputIndented( "Scanning available modules..." );
		$availableModulesStatus = $this->scanModulesOrPlugins( 'modules' );
		if ( !$availableModulesStatus->isGood() ) {
			return $availableModulesStatus;
		}
		$availableModules = $availableModulesStatus->getValue();

		if ( $availableModules === [] ) {
			$this->output( 'none' );
		}
		$this->output( "\n" );
		foreach ( array_chunk( $availableModules, 5 ) as $moduleChunk ) {
			$modules = implode( ', ', $moduleChunk );
			$this->outputIndented( "\t$modules\n" );
		}

		return Status::newGood( $availableModules );
	}

	// @todo: bring below options together in some abstract class where Validator & Reindexer also extend from

	/**
	 * @param string $message
	 * @param string|null $channel
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
	 * @return Status holds true if this is an index, false if it's an alias or if unknown
	 */
	public function isIndex( $indexName ): Status {
		// We must emit a HEAD request before calling the _alias
		// as it may return an error if the index/alias is missing
		if ( !$this->client->getIndex( $indexName )->exists() ) {
			return Status::newGood( false );
		}

		$response = $this->client->request( $indexName . '/_alias' );
		if ( !$response->isOK() ) {
			return Status::newFatal( "Cannot determine if $indexName is an index: "
				. $response->getError() );
		}
		// Only index names are listed as top level keys So if
		// HEAD /$indexName returns HTTP 200 but $indexName is
		// not a top level json key then it's an alias
		return Status::newGood( isset( $response->getData()[$indexName] ) );
	}

	/**
	 * Return a list of index names that points to $aliasName
	 * @param string $aliasName
	 * @return Status holds string[] with index names
	 */
	public function getIndicesWithAlias( $aliasName ): Status {
		// We must emit a HEAD request before calling the _alias
		// as it may return an error if the index/alias is missing
		if ( !$this->client->getIndex( $aliasName )->exists() ) {
			return Status::newGood( [] );
		}
		$response = $this->client->request( $aliasName . '/_alias' );
		if ( !$response->isOK() ) {
			return Status::newFatal( "Cannot fetch indices with alias $aliasName: "
				. $response->getError() );
		}
		return Status::newGood( array_keys( $response->getData() ) );
	}

	/**
	 * Returns true is this is an index thats never been unsed
	 *
	 * Used as a pre-check when deleting indices. This checks that there are no
	 * aliases pointing at it, as all traffic flows through aliases. It
	 * additionally checks the index stats to verify it's never been queried.
	 *
	 * $indexName should be an index and it should exist. If it is an alias or
	 * the index does not exist a fatal status will be returned.
	 *
	 * @param string $indexName The specific name of the index
	 * @return Status When ok, contains true if the index is unused or false otherwise.
	 *   When not good indicates some sort of communication error with elasticsearch.
	 */
	public function isIndexLive( $indexName ): Status {
		try {
			// primary check, verify no aliases point at our index. This invokes
			// the endpoint directly, rather than Index::getAliases, as that method
			// does not check http status codes and can incorrectly report no aliases.
			$aliasResponse = $this->client->requestEndpoint( ( new Endpoints\Indices\GetAlias() )
				->setIndex( $indexName ) );
		} catch ( ResponseException $e ) {
			// Would have expected a NotFoundException? in testing we get ResponseException instead
			if ( $e->getResponse()->getStatus() === 404 ) {
				// We could return an "ok" and false, but since we use this as a check against deletion
				// seems best to return a fatal indicating the calling code should do nothing.
				return Status::newFatal( "Index {$indexName} does not exist" );
			}
			throw $e;
		}
		if ( !$aliasResponse->isOK() ) {
			return Status::newFatal( "Received error response from elasticsearch for GetAliases on $indexName" );
		}
		$aliases = $aliasResponse->getData();
		if ( !isset( $aliases[$indexName] ) ) {
			// Can happen if $indexName is actually an alias and not a real index.
			$keys = count( $aliases ) ? implode( ', ', array_keys( $aliases ) ) : 'empty response';
			return Status::newFatal( "Unexpected aliases response from elasticsearch for $indexName, " .
				"recieved: $keys" );
		}
		if ( $aliases[$indexName]['aliases'] !== [] ) {
			// Any index with aliases is likely to be live
			return Status::newGood( true );
		}
		return Status::newGood( false );
	}

	/**
	 * Refresh the index, return a failed Status after $attempts
	 * @param Index $index
	 * @param int $attempts
	 * @return Status
	 */
	public static function safeRefresh( Index $index, int $attempts = 3 ): Status {
		try {
			MWElasticUtils::withRetry( $attempts, static function () use ( $index ) {
				$resp = $index->refresh();
				$data = $resp->getData();
				if ( is_array( $data ) && isset( $data['_shards'] ) ) {
					$shards = $data['_shards'];
					$tot = $shards['total'] ?? -1;
					$success = $shards['successful'] ?? -1;
					$failed = $shards['failed'] ?? -1;
					if ( $tot - ( $success + $failed ) !== 0 ) {
						throw new RuntimeException( "Inconsistent shard results from response " . json_encode( $data ) );
					}
					if ( $tot !== $success ) {
						throw new RuntimeException( "Some shards failed $failed failed, $success succeeded out of $tot total $success " );
					}
				} else {
					throw new RuntimeException( "Inconsistent refresh response " . print_r( $data, true ) );
				}
			} );
		} catch ( RuntimeException $e ) {
			return Status::newFatal( new \RawMessage( $e->getMessage() ) );
		}
		return Status::newGood();
	}

	/**
	 * Count the number of doc in this index, fail the maintenance script with fatalError after $attempts.
	 * @param Index $index
	 * @param callable $failureFunction a function that accepts a StatusValue with the error message. must fail.
	 * @param int $attempts
	 */
	public static function safeRefreshOrFail( Index $index, callable $failureFunction, int $attempts = 3 ): void {
		$status = self::safeRefresh( $index, $attempts );
		if ( !$status->isGood() ) {
			$failureFunction( $status );
			throw new LogicException( '$failureFunction must fail' );
		}
	}

	/**
	 * Count the number of doc in this index, return a failed Status after $attempts.
	 * @param Index $index
	 * @param int $attempts
	 * @return Status<int>
	 */
	public static function safeCount( Index $index, int $attempts = 3 ): Status {
		try {
			$count = MWElasticUtils::withRetry( $attempts, static function () use ( $index ) {
				$resp = $index->createSearch( '' )->count( '', true );
				$count = $resp->getResponse()->getData()['hits']['total']['value'] ?? null;
				if ( $count === null ) {
					throw new RuntimeException( "Received search response without total hits: " .
												 json_encode( $resp->getResponse()->getData() ) );
				}
				return (int)$count;
			} );
		} catch ( ResponseException | RuntimeException $e ) {
			return Status::newFatal( new \RawMessage( "Failed to count {$index->getName()}: {$e->getMessage()}" ) );
		}
		return Status::newGood( $count );
	}

	/**
	 * Count the number of doc in this index, fail the maintenance script with fatalError after $attempts.
	 * @param Index $index
	 * @param callable $failureFunction a function that accepts a StatusValue with the error message. must fail.
	 * @param int $attempts
	 * @return int
	 */
	public static function safeCountOrFail( Index $index, callable $failureFunction, int $attempts = 3 ): int {
		$status = self::safeCount( $index, $attempts );
		if ( $status->isGood() ) {
			return $status->getValue();
		} else {
			$failureFunction( $status );
			throw new \LogicException( '$failureFunction must fail' );
		}
	}

	/**
	 * @param string $indexQuery
	 * @return Status
	 */
	private function listIndices( string $indexQuery ): Status {
		$response =
			$this->client->requestEndpoint( ( new Endpoints\Indices\Get() )->setIndex( $indexQuery ) );
		if ( !$response->isOK() ) {
			return Status::newFatal( "Cannot fetch index names for $indexQuery: " .
									 $response->getError() );
		}

		return Status::newGood( array_keys( $response->getData() ) );
	}
}
