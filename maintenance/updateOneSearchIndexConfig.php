<?php

namespace CirrusSearch;
use Elastica;
use \Maintenance;
use \ProfileSection;

/**
 * Update the search configuration on the search backend.
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

$IP = getenv( 'MW_INSTALL_PATH' );
if( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );

/**
 * Update the elasticsearch configuration for this index.
 */
class UpdateOneSearchIndexConfig extends Maintenance {
	private $indexType;

	// Are we going to blow the index away and start from scratch?
	private $startOver;

	private $closeOk;

	// Is the index currently closed?
	private $closed = false;

	private $reindexChunkSize;
	private $reindexRetryAttempts;

	private $indexBaseName;
	private $indexIdentifier;
	private $reindexAndRemoveOk;
	// How much should this script indent output?
	private $indent;

	// Set with the name of any old indecies to remove if any must be during the alias maintenance
	// steps.
	private $removeIndecies = false;

	/**
	 * @var boolean are there too few replicas in the index we're making?
	 */
	private $tooFewReplicas = false;

	/**
	 * @var int number of processes to use when reindexing
	 */
	private $reindexProcesses;

	/**
	 * @var string language code we're building for
	 */
	private $langCode;

	/**
	 * @var bool prefix search on any term
	 */
	private $prefixSearchStartsWithAny;

	/**
	 * @var bool use suggestions on text fields
	 */
	private $phraseUseText;

	/**
	 * @var bool print config as it is being checked
	 */
	private $printDebugCheckConfig;

	/**
	 * @var float how much can the reindexed copy of an index is allowed to deviate from the current
	 * copy without triggering a reindex failure
	 */
	private $reindexAcceptableCountDeviation;

	/**
	 * @var array(String) list of available plugins
	 */
	private $availablePlugins;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the configuration or contents of one search index." );
		$this->addOption( 'indexType', 'Index to update.  Either content or general.', true, true );
		$this->addOption( 'indent', 'String used to indent every line output in this script.', false,
			true);
		self::addSharedOptions( $this );
	}

	/**
	 * @param $maintenance Maintenance
	 */
	public static function addSharedOptions( $maintenance ) {
		$maintenance->addOption( 'startOver', 'Blow away the identified index and rebuild it with ' .
			'no data.' );
		$maintenance->addOption( 'forceOpen', "Open the index but do nothing else.  Use this if " .
			"you've stuck the index closed and need it to start working right now." );
		$maintenance->addOption( 'closeOk', "Allow the script to close the index if decides it has " .
			"to.  Note that it is never ok to close an index that you just created. Also note " .
			"that changing analysers might require a reindex for them to take effect so you might " .
			"be better off using --reindexAndRemoveOk and a new --indexIdentifier to rebuild the " .
			"entire index. Defaults to false." );
		$maintenance->addOption( 'indexIdentifier', "Set the identifier of the index to work on.  " .
			"You'll need this if you have an index in production serving queries and you have " .
			"to alter some portion of its configuration that cannot safely be done without " .
			"rebuilding it.  Once you specify a new indexIdentify for this wiki you'll have to " .
			"run this script with the same identifier each time.  Defaults to 'current' which " .
			"infers the currently in use identifier.  You can also use 'now' to set the identifier " .
			"to the current time in seconds which should give you a unique idenfitier.", false, true);
		$maintenance->addOption( 'reindexAndRemoveOk', "If the alias is held by another index then " .
			"reindex all documents from that index (via the alias) to this one, swing the " .
			"alias to this index, and then remove other index.  You'll have to redo all updates ".
			"performed during this operation manually.  Defaults to false." );
		$maintenance->addOption( 'reindexProcesses', 'Number of processess to use in reindex.  ' .
			'Not supported on Windows.  Defaults to 1 on Windows and 10 otherwise.', false, true );
		$maintenance->addOption( 'reindexAcceptableCountDeviation', 'How much can the reindexed ' .
			'copy of an index is allowed to deviate from the current copy without triggering a ' .
			'reindex failure.  Defaults to 5%.', false, true );
		$maintenance->addOption( 'reindexChunkSize', 'Documents per shard to reindex in a batch.   ' .
		    'Note when changing the number of shards that the old shard size is used, not the new ' .
		    'one.  If you see many errors submitting documents in bulk but the automatic retry as ' .
		    'singles works then lower this number.  Defaults to 100.', false, true );
		$maintenance->addOption( 'reindexRetryAttempts', 'Number of times to back off and retry ' .
			'per failure.  Note that failures are not common but if Elasticsearch is in the process ' .
			'of moving a shard this can time out.  This will retry the attempt after some backoff ' .
			'rather than failing the whole reindex process.  Defaults to 5.', false, true );
		$maintenance->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
		$maintenance->addOption( 'debugCheckConfig', 'Print the configuration as it is checked ' .
			'to help debug unexepcted configuration missmatches.' );
	}

	public function execute() {
		global $wgPoolCounterConf,
			$wgLanguageCode,
			$wgCirrusSearchPhraseUseText,
			$wgCirrusSearchPrefixSearchStartsWithAnyWord,
			$wgCirrusSearchMaintenanceTimeout;

		// Make sure we don't flood the pool counter
		unset( $wgPoolCounterConf['CirrusSearch-Search'] );
		// Set the timeout for maintenance actions
		Connection::setTimeout( $wgCirrusSearchMaintenanceTimeout );

		$this->indexType = $this->getOption( 'indexType' );
		$this->startOver = $this->getOption( 'startOver', false );
		$this->closeOk = $this->getOption( 'closeOk', false );
		$this->indexBaseName = $this->getOption( 'baseName', wfWikiId() );
		$this->indent = $this->getOption( 'indent', '' );
		$this->reindexAndRemoveOk = $this->getOption( 'reindexAndRemoveOk', false );
		$this->reindexProcesses = $this->getOption( 'reindexProcesses', wfIsWindows() ? 1 : 10 );
		$this->reindexAcceptableCountDeviation = $this->parsePotentialPercent(
			$this->getOption( 'reindexAcceptableCountDeviation', '5%' ) );
		$this->reindexChunkSize = $this->getOption( 'reindexChunkSize', 100 );
		$this->reindexRetryAttempts = $this->getOption( 'reindexRetryAttempts', 5 );
		$this->printDebugCheckConfig = $this->getOption( 'debugCheckConfig', false );
		$this->langCode = $wgLanguageCode;
		$this->prefixSearchStartsWithAny = $wgCirrusSearchPrefixSearchStartsWithAnyWord;
		$this->phraseUseText = $wgCirrusSearchPhraseUseText;

		try{
			$indexTypes = Connection::getAllIndexTypes();
			if ( !in_array( $this->indexType, $indexTypes ) ) {
				$this->error( 'indexType option must be one of ' .
					implode( ', ', $indexTypes ), 1 );
			}

			$this->checkElasticsearchVersion();
			$this->scanAvailablePlugins();

			if ( $this->getOption( 'forceOpen', false ) ) {
				$this->getIndex()->open();
				return;
			}

			$this->indexIdentifier = $this->pickIndexIdentifierFromOption( $this->getOption( 'indexIdentifier', 'current' ) );
			$this->validateIndex();
			$this->validateAnalyzers();
			$this->validateMapping();
			$this->validateAlias();
			$this->updateVersions();

			if ( $this->closed ) {
				$this->getIndex()->open();
			}
		} catch ( \Elastica\Exception\Connection\HttpException $e ) {
			$message = $e->getMessage();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->error( "Http error communicating with Elasticsearch:  $message.\n", 1 );
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$type = get_class( $e );
			$message = $e->getMessage();
			$trace = $e->getTraceAsString();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->error( "Elasticsearch failed in an unexpected way.  This is always a bug in CirrusSearch.\n" .
				"Error type: $type\n" .
				"Message: $message\n" .
				"Trace:\n" . $trace, 1 );
		}
	}

	private function checkElasticsearchVersion() {
		$this->output( $this->indent . 'Fetching Elasticsearch version...' );
		$result = Connection::getClient()->request( '' );
		$result = $result->getData();
		$result = $result[ 'version' ][ 'number' ];
		$this->output( "$result..." );
		if ( !preg_match( '/^(1|2)./', $result ) ) {
			$this->output( "Not supported!\n" );
			$this->error( "Only Elasticsearch 1.x is supported.  Your version: $result.", 1 );
		} else {
			$this->output( "ok\n" );
		}
	}

	private function scanAvailablePlugins() {
		$this->output( $this->indent . "Scanning available plugins..." );
		$result = Connection::getClient()->request( '_nodes' );
		$result = $result->getData();
		$first = true;
		foreach ( array_values( $result[ 'nodes' ] ) as $node ) {
			$plugins = array();
			foreach ( $node[ 'plugins' ] as $plugin ) {
				$plugins[] = $plugin[ 'name' ];
			}
			if ( $first ) {
				$this->availablePlugins = $plugins;
			} else {
				$this->availablePlugins = array_intersect( $this->availablePlugins, $plugins );
			}
		}
		if ( count( $this->availablePlugins ) === 0 ) {
			$this->output( 'none' );
		}
		$this->output( "\n" );
		foreach ( array_chunk( $this->availablePlugins, 5 ) as $pluginChunk ) {
			$plugins = implode( ', ', $pluginChunk );
			$this->output( $this->indent . "\t$plugins\n" );
		}
	}

	private function updateVersions() {
		$child = $this->runChild( 'CirrusSearch\UpdateVersionIndex' );
		$child->mOptions['baseName'] = $this->indexBaseName;
		$child->mOptions['update'] = true;
		$child->mOptions['indent'] = "\t";
		$child->execute();
	}

	private function validateIndex() {
		if ( $this->startOver ) {
			$this->output( $this->indent . "Blowing away index to start over..." );
			$this->createIndex( true );
			$this->output( "ok\n" );
			return;
		}
		if ( !$this->getIndex()->exists() ) {
			$this->output( $this->indent . "Creating index..." );
			$this->createIndex( false );
			$this->output( "ok\n" );
			return;
		}
		$this->output( $this->indent . "Index exists so validating...\n" );
		$this->validateIndexSettings();
	}

	private function validateIndexSettings() {
		$this->output( $this->indent . "\tValidating number of shards..." );
		$settings = $this->getSettings();
		$actualShardCount = $settings[ 'number_of_shards' ];
		if ( $actualShardCount == $this->getShardCount() ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualShardCount but should be " . $this->getShardCount() . "...cannot correct!\n" );
			$this->error(
				"Number of shards is incorrect and cannot be changed without a rebuild. You can solve this\n" .
				"problem by running this program again with either --startOver or --reindexAndRemoveOk.  Make\n" .
				"sure you understand the consequences of either choice..  This script will now continue to\n" .
				"validate everything else.", 1 );
		}

		$this->output( $this->indent . "\tValidating number of replicas..." );
		$actualReplicaCount = $settings[ 'number_of_replicas' ];
		if ( $actualReplicaCount == $this->getReplicaCount() ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualReplicaCount but should be " . $this->getReplicaCount() . '...' );
			$this->getIndex()->getSettings()->setNumberOfReplicas( $this->getReplicaCount() );
			$this->output( "corrected\n" );
		}
	}

	private function validateAnalyzers() {
		$this->output( $this->indent . "Validating analyzers..." );
		$settings = $this->getSettings();
		$analysisConfig = new AnalysisConfigBuilder( $this->langCode );
		$requiredAnalyzers = $analysisConfig->buildConfig();
		if ( $this->checkConfig( $settings[ 'analysis' ], $requiredAnalyzers ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "different..." );
			if ( $this->closeOk ) {
				$this->getIndex()->close();
				$this->closed = true;
				$this->getIndex()->getSettings()->set( $requiredAnalyzers );
				$this->output( "corrected\n" );
			} else {
				$this->output( "cannot correct\n" );
				$this->error("This script encountered an index difference that requires that the index be\n" .
					"closed, modified, and then reopened.  To allow this script to close the index run it\n" .
					"with the --closeOk parameter and it'll close the index for the briefest possible time\n" .
					"Note that the index will be unusable while closed.", 1 );
			}
		}
	}

	/**
	 * Load the settings array.  You can't use this to set the settings, use $this->getIndex()->getSettings() for that.
	 * @return array of settings
	 */
	private function getSettings() {
		return $this->getIndex()->getSettings()->get();
	}

	private function validateMapping() {
		global $wgCirrusSearchOptimizeIndexForExperimentalHighlighter;

		$this->output( $this->indent . "Validating mappings..." );
		if ( $wgCirrusSearchOptimizeIndexForExperimentalHighlighter &&
				!in_array( 'experimental highlighter', $this->availablePlugins ) ) {
			$this->output( "impossible!\n" );
			$this->error( "wgCirrusSearchOptimizeIndexForExperimentalHighlighter is set to true but the " .
				"'experimental highlighter' plugin is not installed on all hosts.", 1 );
		}

		$requiredPageMappings = new MappingConfigBuilder(
			$this->prefixSearchStartsWithAny, $this->phraseUseText,
			$wgCirrusSearchOptimizeIndexForExperimentalHighlighter );
		$requiredPageMappings = $requiredPageMappings->buildConfig();

		if ( !$this->checkMapping( $requiredPageMappings ) ) {
			// TODO Conflict resolution here might leave old portions of mappings
			$action = new \Elastica\Type\Mapping( $this->getPageType() );
			foreach ( $requiredPageMappings as $key => $value ) {
				$action->setParam( $key, $value );
			}
			try {
				$action->send();
				$this->output( "corrected\n" );
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$this->output( "failed!\n" );
				$message = $e->getMessage();
				$this->error( "Couldn't update mappings.  Here is elasticsearch's error message: $message\n", 1 );
			}
		}
	}

	/**
	 * Check that the mapping returned from Elasticsearch is as we want it.
	 * @param array $requiredPageMappings the mappings we want
	 * @return bool is the mapping good enough for us?
	 */
	private function checkMapping( $requiredPageMappings ) {
		$actualMappings = $this->getIndex()->getMapping();
		$this->output( "\n" . $this->indent . "\tValidating mapping for page type..." );
		if ( array_key_exists( 'page', $actualMappings ) &&
				$this->checkConfig( $actualMappings[ 'page' ], $requiredPageMappings ) ) {
			$this->output( "ok\n" );
			return true;
		} else {
			$this->output( "different..." );
			return false;
		}
	}

	/**
	 * @param $actual
	 * @param $required array
	 * @return bool
	 */
	private function checkConfig( $actual, $required, $indent = null ) {
		if ( $indent === null ) {
			$indent = $this->indent . "\t\t";
		}
		foreach( $required as $key => $value ) {
			$this->debugCheckConfig( "\n$indent$key: " );
			if ( !array_key_exists( $key, $actual ) ) {
				$this->debugCheckConfig( "not found..." );
				if ( $key === '_all' ) {
					// The _all field never comes back so we just have to assume it
					// is set correctly.
					$this->debugCheckConfig( "was the all field so skipping..." );
					continue;
				}
				return false;
			}
			if ( is_array( $value ) ) {
				$this->debugCheckConfig( "descend..." );
				if ( !is_array( $actual[ $key ] ) ) {
					$this->debugCheckConfig( "other not array..." );
					return false;
				}
				if ( !$this->checkConfig( $actual[ $key ], $value, $indent . "\t" ) ) {
					return false;
				}
				continue;
			}

			$actual[ $key ] = $this->normalizeConfigValue( $actual[ $key ] );
			$value = $this->normalizeConfigValue( $value );
			$this->debugCheckConfig( $actual[ $key ] . " ?? $value..." );
			// Note that I really mean !=, not !==.  Coercion is cool here.
			// print $actual[ $key ] . "  $value\n";
			if ( $actual[ $key ] != $value ) {
				$this->debugCheckConfig( 'different...' );
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize a config value for comparison.  Elasticsearch will accept all kinds
	 * of config values but it tends to through back 'true' for true and 'false' for
	 * false so we normalize everything.  Sometimes, oddly, it'll through back false
	 * for false....
	 * @param mixed $value config value
	 * @return mixes value normalized
	 */
	private function normalizeConfigValue( $value ) {
		if ( $value === true ) {
			return 'true';
		} else if ( $value === false ) {
			return 'false';
		}
		return $value;
	}

	private function debugCheckConfig( $string ) {
		if ( $this->printDebugCheckConfig ) {
			$this->output( $string );
		}
	}

	private function validateAlias() {
		$this->output( $this->indent . "Validating aliases...\n" );
		// Since validate the specific alias first as that can cause reindexing
		// and we want the all index to stay with the old index during reindexing
		$this->validateSpecificAlias();
		$this->validateAllAlias();
		// Note that at this point both the old and the new index can have the all
		// alias but this should be for a very short time.  Like, under a second.
		$this->removeOldIndeciesIfRequired();
	}

	/**
	 * Validate the alias that is just for this index's type.
	 */
	private function validateSpecificAlias() {
		global $wgCirrusSearchMaintenanceTimeout;

		$this->output( $this->indent . "\tValidating $this->indexType alias..." );
		$otherIndeciesWithAlias = array();
		$specificAliasName = $this->getIndexTypeName();
		$status = Connection::getClient()->getStatus();
		if ( $status->indexExists( $specificAliasName ) ) {
			$this->output( "is an index..." );
			if ( $this->startOver ) {
				Connection::getClient()->getIndex( $specificAliasName )->delete();
				$this->output( "index removed..." );
			} else {
				$this->output( "cannot correct!\n" );
				$this->error(
					"There is currently an index with the name of the alias.  Rerun this\n" .
					"script with --startOver and it'll remove the index and continue.\n", 1 );
			}
		} else {
			foreach ( $status->getIndicesWithAlias( $specificAliasName ) as $index ) {
				if( $index->getName() === $this->getSpecificIndexName() ) {
					$this->output( "ok\n" );
					if ( $this->tooFewReplicas ) {
						$this->validateIndexSettings();
					}
					return;
				} else {
					$otherIndeciesWithAlias[] = $index->getName();
				}
			}
		}
		if ( !$otherIndeciesWithAlias ) {
			$this->output( "alias is free..." );
			$this->getIndex()->addAlias( $specificAliasName, false );
			$this->output( "corrected\n" );
			if ( $this->tooFewReplicas ) {
				$this->validateIndexSettings();
			}
			return;
		}
		if ( $this->reindexAndRemoveOk ) {
			$this->output( "is taken...\n" );
			$this->output( $this->indent . "\tReindexing...\n" );
			// Muck with $this->indent because reindex is used to running at the top level.
			$saveIndent = $this->indent;
			$this->indent = $this->indent . "\t\t";
			$this->reindex();
			$this->indent = $saveIndent;
			if ( $this->tooFewReplicas ) {
				// Optimize the index so it'll be more compact for replication.  Not required
				// but should be helpful.
				$this->output( $this->indent . "\tOptimizing..." );
				try {
					// Reset the timeout just in case we lost it somehwere along the line
					Connection::setTimeout( $wgCirrusSearchMaintenanceTimeout );
					$this->getIndex()->optimize( array( 'max_num_segments' => 5 ) );
					$this->output( "Done\n" );
				} catch ( \Elastica\Exception\Connection\HttpException $e ) {
					if ( $e->getMessage() === 'Operation timed out' ) {
						$this->output( "Timed out...Continuing any way\n" );
						// To continue without blowing up we need to reset the connection.
						Connection::destroySingleton();
						Connection::setTimeout( $wgCirrusSearchMaintenanceTimeout );
					} else {
						throw $e;
					}
				}
				$this->validateIndexSettings();
				$this->output( $this->indent . "\tWaiting for all shards to start...\n" );
				$expectedActive = $this->getShardCount() * ( 1 + $this->getReplicaCount() );
				$indexName = $this->getSpecificIndexName();
				$path = "_cluster/health/$indexName";
				$each = 0;
				while ( true ) {
					$response = Connection::getClient()->request( $path );
					if ( $response->hasError() ) {
						$this->error( 'Error fetching index health but going to retry.  Message: ' + $response->getError() );
						sleep( 1 );
						continue;
					}
					$health = $response->getData();
					$active = $health[ 'active_shards' ];
					$relocating = $health[ 'relocating_shards' ];
					$initializing = $health[ 'initializing_shards' ];
					$unassigned = $health[ 'unassigned_shards' ];
					if ( $each === 0 || $active === $expectedActive ) {
						$this->output( $this->indent . "\t\tactive:$active/$expectedActive relocating:$relocating " .
							"initializing:$initializing unassigned:$unassigned\n" );
						if ( $active === $expectedActive ) {
							break;
						}
					}
					$each = ( $each + 1 ) % 20;
					sleep( 1 );
				}
			}
			$this->output( $this->indent . "\tSwapping alias...");
			$this->getIndex()->addAlias( $specificAliasName, true );
			$this->output( "done\n" );
			$this->removeIndecies = $otherIndeciesWithAlias;
			return;
		}
		$this->output( "cannot correct!\n" );
		$this->error(
			"The alias is held by another index which means it might be actively serving\n" .
			"queries.  You can solve this problem by running this program again with\n" .
			"--reindexAndRemoveOk.  Make sure you understand the consequences of either\n" .
			"choice.", 1 );
	}

	public function validateAllAlias() {
		$this->output( $this->indent . "\tValidating all alias..." );
		$allAliasName = Connection::getIndexName( $this->indexBaseName );
		$status = Connection::getClient()->getStatus();
		if ( $status->indexExists( $allAliasName ) ) {
			$this->output( "is an index..." );
			if ( $this->startOver ) {
				Connection::getClient()->getIndex( $allAliasName )->delete();
				$this->output( "index removed..." );
			} else {
				$this->output( "cannot correct!\n" );
				$this->error(
					"There is currently an index with the name of the alias.  Rerun this\n" .
					"script with --startOver and it'll remove the index and continue.\n", 1 );
				return;
			}
		} else {
			foreach ( $status->getIndicesWithAlias( $allAliasName ) as $index ) {
				if( $index->getName() === $this->getSpecificIndexName() ) {
					$this->output( "ok\n" );
					return;
				}
			}
		}
		$this->output( "alias not already assigned to this index..." );
		// We'll remove the all alias from the indecies that we're about to delete while
		// we add it to this index.  Elastica doesn't support this well so we have to
		// build the request to Elasticsearch ourselves.
		$data = array(
			'action' => array(
				array( 'add' => array( 'index' => $this->getSpecificIndexName(), 'alias' => $allAliasName ) )
			)
		);
		if ( $this->removeIndecies ) {
			foreach ( $this->removeIndecies as $oldIndex ) {
				$data['action'][] = array( 'remove' => array( 'index' => $oldIndex, 'alias' => $allAliasName ) );
			}
		}
		Connection::getClient()->request( '_aliases', \Elastica\Request::POST, $data );
		$this->output( "corrected\n" );
	}

	public function removeOldIndeciesIfRequired() {
		if ( $this->removeIndecies ) {
			$this->output( $this->indent . "\tRemoving old indecies...\n" );
			foreach ( $this->removeIndecies as $oldIndex ) {
				$this->output( $this->indent . "\t\t$oldIndex..." );
				Connection::getClient()->getIndex( $oldIndex )->delete();
				$this->output( "done\n" );
			}
		}
	}

	/**
	 * Dump everything from the live index into the one being worked on.
	 */
	private function reindex() {
		global $wgCirrusSearchMaintenanceTimeout,
			$wgCirrusSearchRefreshInterval;

		$settings = $this->getIndex()->getSettings();
		$settings->set( array(
			'refresh_interval' => -1,           // This is supposed to help with bulk index io load.
			'merge.policy.merge_factor' => 20,  // This is supposed to help with bulk index io load.
		) );

		if ( $this->reindexProcesses > 1 ) {
			$fork = new ReindexForkController( $this->reindexProcesses );
			$forkResult = $fork->start();
			// Forking clears the timeout so we have to reinstate it.
			Connection::setTimeout( $wgCirrusSearchMaintenanceTimeout );

			switch ( $forkResult ) {
			case 'child':
				$this->reindexInternal( $this->reindexProcesses, $fork->getChildNumber() );
				die( 0 );
			case 'done':
				break;
			default:
				$this->error( "Unexpected result while forking:  $forkResult", 1 );
			}

			$this->output( $this->indent . "Verifying counts..." );
			// We can't verify counts are exactly equal because they won't be - we still push updates into
			// the old index while reindexing the new one.
			$oldCount = (float) Connection::getPageType( $this->indexBaseName, $this->indexType )->count();
			$this->getIndex()->refresh();
			$newCount = (float) $this->getPageType()->count();
			$difference = $oldCount > 0 ? abs( $oldCount - $newCount ) / $oldCount : 0;
			if ( $difference > $this->reindexAcceptableCountDeviation ) {
				$this->output( "Not close enough!  old=$oldCount new=$newCount difference=$difference\n" );
				$this->error( 'Failed to load index - counts not close enough.  ' .
					"old=$oldCount new=$newCount difference=$difference.  " .
					'Check for warnings above.', 1 );
			}
			$this->output( "done\n" );
		} else {
			$this->reindexInternal( 1, 1 );
		}

		// Revert settings changed just for reindexing
		$settings->set( array(
			'refresh_interval' => $wgCirrusSearchRefreshInterval . 's',
			'merge.policy.merge_factor' => 10,
		) );
	}

	private function reindexInternal( $children, $childNumber ) {
		global $wgCirrusSearchOptimizeIndexForExperimentalHighlighter;

		$filter = null;
		$messagePrefix = "";
		if ( $childNumber === 1 && $children === 1 ) {
			$this->output( $this->indent . "Starting single process reindex\n" );
		} else {
			if ( $childNumber >= $children ) {
				$this->error( "Invalid parameters - childNumber >= children ($childNumber >= $children) ", 1 );
			}
			$messagePrefix = "[$childNumber] ";
			$this->output( $this->indent . $messagePrefix . "Starting child process reindex\n" );
			// Note that it is not ok to abs(_uid.hashCode) because hashCode(Integer.MIN_VALUE) == Integer.MIN_VALUE
			$filter = new Elastica\Filter\Script(
				"(doc['_uid'].value.hashCode() & Integer.MAX_VALUE) % $children == $childNumber" );
		}
		$pageProperties = new MappingConfigBuilder(
			$this->prefixSearchStartsWithAny, $this->phraseUseText,
			$wgCirrusSearchOptimizeIndexForExperimentalHighlighter );
		$pageProperties = $pageProperties->buildConfig();
		$pageProperties = $pageProperties[ 'properties' ];
		try {
			$query = new Elastica\Query();
			$query->setFields( array( '_id', '_source' ) );
			if ( $filter ) {
				$query->setFilter( $filter );
			}

			// Note here we dump from the current index (using the alias) so we can use Connection::getPageType
			$result = Connection::getPageType( $this->indexBaseName, $this->indexType )
				->search( $query, array(
					'search_type' => 'scan',
					'scroll' => '1h',
					'size'=> $this->reindexChunkSize,
				)
			);
			$totalDocsToReindex = $result->getResponse()->getData();
			$totalDocsToReindex = $totalDocsToReindex['hits']['total'];
			$this->output( $this->indent . $messagePrefix . "About to reindex $totalDocsToReindex documents\n" );
			$operationStartTime = microtime( true );
			$completed = 0;
			while ( true ) {
				wfProfileIn( __METHOD__ . '::receiveDocs' );
				$result = $this->getIndex()->search( array(), array(
					'scroll_id' => $result->getResponse()->getScrollId(),
					'scroll' => '1h'
				) );
				wfProfileOut( __METHOD__ . '::receiveDocs' );
				if ( !$result->count() ) {
					$this->output( $this->indent . $messagePrefix . "All done\n" );
					break;
				}
				wfProfileIn( __METHOD__ . '::packageDocs' );
				$documents = array();
				while ( $result->current() ) {
					// Build the new document to just contain keys which have a mapping in the new properties.  To clean
					// out any old fields that we no longer use.  Note that this filter is only a single level which is
					// likely ok for us.
					$document = new \Elastica\Document( $result->current()->getId(),
						array_intersect_key( $result->current()->getSource(), $pageProperties ) );
					// Note that while setting the opType to create might improve performance slightly it can cause
					// trouble if the scroll returns the same id twice.  It can do that if the document is updated
					// during the scroll process.  I'm unclear on if it will always do that, so you still have to
					// perform the date based catch up after the reindex.
					$documents[] = $document;
					$result->next();
				}
				wfProfileOut( __METHOD__ . '::packageDocs' );
				$this->sendDocumentsWithRetry( $messagePrefix, $documents );
				$completed += $result->count();
				$rate = round( $completed / ( microtime( true ) - $operationStartTime ) );
				$this->output( $this->indent . $messagePrefix .
					"Reindexed $completed/$totalDocsToReindex documents at $rate/second\n");
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			// Note that we can't fail the master here, we have to check how many documents are in the new index in the master.
			wfLogWarning( "Search backend error during reindex.  Error message is:  " . $e->getMessage() );
			die( 1 );
		}
	}

	private function sendDocumentsWithRetry( $messagePrefix, $documents ) {
		$profiler = new ProfileSection( __METHOD__ );

		$errors = 0;
		while ( true ) {
			if ( $errors < $this->reindexRetryAttempts ) {
				try {
					$this->sendDocuments( $messagePrefix, $documents );
					return;
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					$errors += 1;
					// Random backoff with lowest possible upper bound as 16 seconds.
					// With the default mximum number of errors (5) this maxes out at 256 seconds.
					$seconds = rand( 1, pow( 2, 3 + $errors ) );
					$this->output( $this->indent . $messagePrefix . "Caught an error retrying as singles.  " .
						"Backing off for $seconds and retrying.\n" );
					sleep( $seconds );
				}
			} else {
				$this->sendDocuments( $messagePrefix, $documents );
				return;
			}
		}
	}

	private function sendDocuments( $messagePrefix, $documents ) {
		try {
			$updateResult = $this->getPageType()->addDocuments( $documents );
			// if ( rand( 0, 9 ) < 3 ) {
			// 	throw new \Elastica\Exception\InvalidException();
			// }
			wfDebugLog( 'CirrusSearch', 'Update completed in ' . $updateResult->getEngineTime() . ' (engine) millis' );
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$this->output( $this->indent . $messagePrefix . "Error adding documents in bulk.  Retrying as singles.\n" );
			foreach ( $documents as $document ) {
				// Continue using the bulk api because we're used to it.
				$updateResult = $this->getPageType()->addDocuments( array( $document ) );
				// if ( rand( 0, 9 ) < 3 ) {
				// 	throw new \Elastica\Exception\InvalidException();
				// }
				wfDebugLog( 'CirrusSearch', 'Update completed in ' . $updateResult->getEngineTime() . ' (engine) millis' );
			}
		}
	}

	private function createIndex( $rebuild ) {
		global $wgCirrusSearchRefreshInterval;

		$analysisConfig = new AnalysisConfigBuilder( $this->langCode );
		$this->getIndex()->create( array(
			'settings' => array(
				'number_of_shards' => $this->getShardCount(),
				'number_of_replicas' => $this->reindexAndRemoveOk ? 0 : $this->getReplicaCount(),
				'analysis' => $analysisConfig->buildConfig(),
				'translog.flush_threshold_ops' => 50000,   // This is supposed to help with bulk index io load.
				'index.query.default_field' => 'page.text', // Since the _all field is disabled, we should query something.
				'refresh_interval' => $wgCirrusSearchRefreshInterval . 's'
			)
		), $rebuild );
		$this->closeOk = false;
		$this->tooFewReplicas = $this->reindexAndRemoveOk;
	}

	/**
	 * Pick the index identifier from the provided command line option.
	 * @param string $option command line option
	 *          'now'        => current time
	 *          'current'    => if there is just one index for this type then use its identifier
	 *          other string => that string back
	 * @return string index identifier to use
	 */
	private function pickIndexIdentifierFromOption( $option ) {
		$typeName = $this->getIndexTypeName();
		if ( $option === 'now' ) {
			$identifier = strval( time() );
			$this->output( $this->indent . "Setting index identifier...${typeName}_${identifier}\n" );
			return $identifier;
		}
		if ( $option === 'current' ) {
			$this->output( $this->indent . 'Infering index identifier...' );
			$found = array();
			foreach ( Connection::getClient()->getStatus()->getIndexNames() as $name ) {
				if ( substr( $name, 0, strlen( $typeName ) ) === $typeName ) {
					$found[] = $name;
				}
			}
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
			$this->output( "${typeName}_${identifier}\n ");
			return $identifier;
		}
		return $option;
	}

	/**
	 * @return \Elastica\Index being updated
	 */
	private function getIndex() {
		return Connection::getIndex( $this->indexBaseName, $this->indexType, $this->indexIdentifier );
	}

	/**
	 * @return string name of the index being updated
	 */
	private function getSpecificIndexName() {
		return Connection::getIndexName( $this->indexBaseName, $this->indexType, $this->indexIdentifier );
	}

	/**
	 * @return string name of the index type being updated
	 */
	private function getIndexTypeName() {
		return Connection::getIndexName( $this->indexBaseName, $this->indexType );
	}

	/**
	 * Get the type being updated by the search config.
	 */
	private function getPageType() {
		return $this->getIndex()->getType( Connection::PAGE_TYPE_NAME );
	}

	private function getShardCount() {
		global $wgCirrusSearchShardCount;
		if ( !isset( $wgCirrusSearchShardCount[ $this->indexType ] ) ) {
			$this->error( 'Could not find a shard count for ' . $this->indexType . '.  Did you add an index to ' .
				'$wgCirrusSearchNamespaceMappings but forget to add it to $wgCirrusSearchShardCount?', 1 );
		}
		return $wgCirrusSearchShardCount[ $this->indexType ];
	}

	private function getReplicaCount() {
		global $wgCirrusSearchReplicaCount;
		if ( !isset( $wgCirrusSearchReplicaCount[ $this->indexType ] ) ) {
			$this->error( 'Could not find a replica count for ' . $this->indexType . '.  Did you add an index to ' .
				'$wgCirrusSearchNamespaceMappings but forget to add it to $wgCirrusSearchReplicaCount?', 1 );
		}
		return $wgCirrusSearchReplicaCount[ $this->indexType ];
	}

	private function parsePotentialPercent( $str ) {
		$result = floatval( $str );
		if ( strpos( $str, '%' ) === false ) {
			return $result;
		}
		return $result / 100;
	}
}

$maintClass = "CirrusSearch\UpdateOneSearchIndexConfig";
require_once RUN_MAINTENANCE_IF_MAIN;
