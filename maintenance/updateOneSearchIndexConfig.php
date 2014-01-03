<?php

namespace CirrusSearch;
use Elastica;
use \Maintenance;

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

	private $reindexChunkSize = 1000;

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
	 * @var float how much can the reindexed copy of an index is allowed to deviate from the current
	 * copy without triggering a reindex failure
	 */
	private $reindexAcceptableCountDeviation;

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
		$maintenance->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
	}

	public function execute() {
		global $wgPoolCounterConf;
		// Make sure we don't flood the pool counter
		unset( $wgPoolCounterConf['CirrusSearch-Search'] );

		try{
			$this->indexType = $this->getOption( 'indexType' );
			$indexTypes = Connection::getAllIndexTypes();
			if ( !in_array( $this->indexType, $indexTypes ) ) {
				$this->error( 'indexType option must be one of ' .
					implode( ', ', $indexTypes ), 1 );
			}
			$this->indent = $this->getOption( 'indent', '' );
			if ( $this->getOption( 'forceOpen', false ) ) {
				$this->getIndex()->open();
				return;
			}
			$this->startOver = $this->getOption( 'startOver', false );
			$this->closeOk = $this->getOption( 'closeOk', false );
			$this->indexBaseName = $this->getOption( 'baseName', wfWikiId() );
			$this->indexIdentifier = $this->pickIndexIdentifierFromOption( $this->getOption( 'indexIdentifier', 'current' ) );
			$this->reindexAndRemoveOk = $this->getOption( 'reindexAndRemoveOk', false );
			$this->reindexProcesses = $this->getOption( 'reindexProcesses', wfIsWindows() ? 1 : 10 );
			$this->reindexAcceptableCountDeviation = self::parsePotentialPercent(
				$this->getOption( 'reindexAcceptableCountDeviation', '5%' ) );

			$this->validateIndex();
			$this->validateAnalyzers();
			$this->validateMapping();
			$this->validateAlias();

			if ( $this->closed ) {
				$this->getIndex()->open();
			}
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$type = get_class( $e );
			$message = $e->getMessage();
			$trace = $e->getTraceAsString();
			$this->output( "Unexpected Elasticsearch failure.\n" );
			$this->error( "Elasticsearch failed in an unexpected way.  This is always a bug in CirrusSearch.\n" .
				"Error type: $type\n" .
				"Message: $message\n" .
				"Trace:\n" . $trace, 1 );
		}
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
		$settingsObject = $this->getIndex()->getSettings();
		$settings = $settingsObject->get();
		$actualShardCount = $settings[ 'index.number_of_shards' ];
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
		$actualReplicaCount = $settings[ 'index.number_of_replicas' ];
		if ( $actualReplicaCount == $this->getReplicaCount() ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualReplicaCount but should be " . $this->getReplicaCount() . '...' );
			$settingsObject->setNumberOfReplicas( $this->getReplicaCount() );
			$this->output( "corrected\n" );
		}
	}

	private function validateAnalyzers() {
		$this->output( $this->indent . "Validating analyzers..." );
		$settingsObject = $this->getIndex()->getSettings();
		$settings = $settingsObject->get();
		$requiredAnalyzers = AnalysisConfigBuilder::build();
		if ( $this->vaActualMatchRequired( 'index.analysis', $settings, $requiredAnalyzers ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "different..." );
			$index = $this->getIndex();
			if ( $this->closeOk ) {
				$this->getIndex()->close();
				$this->closed = true;
				$settingsObject->set( $requiredAnalyzers );
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
	 * @param $prefix
	 * @param $settings
	 * @param $required array
	 * @return bool
	 */
	private function vaActualMatchRequired( $prefix, $settings, $required ) {
		foreach( $required as $key => $value ) {
			$settingsKey = $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				if ( !$this->vaActualMatchRequired( $settingsKey, $settings, $value ) ) {
					return false;
				}
				continue;
			}
			// Note that I really mean !=, not !==.  Coercion is cool here.
			if ( !array_key_exists( $settingsKey, $settings ) || $settings[ $settingsKey ] != $value ) {
				return false;
			}
		}
		return true;
	}

	private function validateMapping() {
		$this->output( $this->indent . "Validating mappings...\n" );
		$actualMappings = $this->getIndex()->getMapping();
		$actualMappings = $actualMappings[ $this->getIndex()->getName() ];

		$this->output( $this->indent . "\tValidating mapping for page type..." );
		$requiredPageMappings = MappingConfigBuilder::build();
		if ( array_key_exists( 'page', $actualMappings ) &&
				$this->vmActualMatchRequired( $actualMappings[ 'page' ], $requiredPageMappings ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "different..." );
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
	 * @param $actual
	 * @param $required array
	 * @return bool
	 */
	private function vmActualMatchRequired( $actual, $required ) {
		foreach( $required as $key => $value ) {
			if ( !array_key_exists( $key, $actual ) ) {
				return false;
			}
			if ( is_array( $value ) ) {
				if ( !is_array( $actual[ $key ] ) ) {
					return false;
				}
				if ( !$this->vmActualMatchRequired( $actual[ $key ], $value ) ) {
					return false;
				}
				continue;
			}

			if ( $actual[ $key ] === 'false' ) {
				$actual[ $key ] = false;
			}
			// Note that I really mean !=, not !==.  Coercion is cool here.
			if ( $actual[ $key ] != $value ) {
				return false;
			}
		}
		return true;
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
		$this->output( $this->indent . "\tValidating $this->indexType alias..." );
		$otherIndeciesWithAlias = array();
		$specificAliasName = $this->getIndexTypeName();
		$status = Connection::getClient()->getStatus();
		if ( $status->indexExists( $specificAliasName ) ) {
			$this->output( "is an index..." );
			if ( $this->startOver ) {
				Connection::getClient()
					->getIndex( $this->indexBaseName, $specificAliasName )->delete();
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
				$this->getIndex()->optimize( array( 'max_num_segments' => 5 ) );
				$this->output( "Done\n" );
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
			foreach ( $this->getIndicesWithAlias( $allAliasName ) as $index ) {
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
		$settings = $this->getIndex()->getSettings();
		$settings->set( array(
			'refresh_interval' => -1,           // This is supposed to help with bulk index io load.
			'merge.policy.merge_factor' => 20,  // This is supposed to help with bulk index io load.
		) );

		if ( $this->reindexProcesses > 1 ) {
			$fork = new ReindexForkController( $this->reindexProcesses );
			$forkResult = $fork->start();
			switch ( $forkResult ) {
			case 'child':
				$this->reindexInternal( $this->reindexProcesses, $fork->childNumber );
				die( 0 );
			case 'done':
				break;
			default:
				$this->error( "Unexpected result while forking:  $forkResult", 1 );
			}

			$this->output( $this->indent . "Verifying counts..." );
			// We can't verify counts are exactly equal because they won't be - we still push updates into
			// the old index while reindexing the new one.
			$oldCount = (float) Connection::getPageType( $this->indexType )->count();
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
			'refresh_interval' => '1s',
			'merge.policy.merge_factor' => 10,
		) );
	}

	private function reindexInternal( $children, $childNumber ) {
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
		$pageProperties = MappingConfigBuilder::build();
		$pageProperties = $pageProperties[ 'properties' ];
		try {
			$query = new Elastica\Query();
			$query->setFields( array( '_id', '_source' ) );
			if ( $filter ) {
				$query->setFilter( $filter );
			}

			// Note here we dump from the current index (using the alias) so we can use Connection::getPageType
			$result = Connection::getPageType( $this->indexType )->search( $query, array(
				'search_type' => 'scan',
				'scroll' => '1h',
				'size'=> $this->reindexChunkSize,
			) );
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
					$document->setOpType( 'create' );
					$documents[] = $document;
					$result->next();
				}
				wfProfileOut( __METHOD__ . '::packageDocs' );
				wfProfileIn( __METHOD__ . '::sendDocs' );
				$updateResult = $this->getPageType()->addDocuments( $documents );
				wfDebugLog( 'CirrusSearch', 'Update completed in ' . $updateResult->getEngineTime() . ' (engine) millis' );
				wfProfileOut( __METHOD__ . '::sendDocs' );
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

	private function createIndex( $rebuild ) {
		$this->getIndex()->create( array(
			'settings' => array(
				'number_of_shards' => $this->getShardCount(),
				'number_of_replicas' => $this->reindexAndRemoveOk ? 0 : $this->getReplicaCount(),
				'analysis' => AnalysisConfigBuilder::build(),
				'translog.flush_threshold_ops' => 50000,   // This is supposed to help with bulk index io load.
				'index.query.default_field' => 'page.text', // Since the _all field is disabled, we should query something.
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
			$found = null;
			$moreThanOne = array();
			foreach ( Connection::getClient()->getStatus()->getIndexNames() as $name ) {
				if ( substr( $name, 0, strlen( $typeName ) ) === $typeName ) {
					$found[] = $name;
				}
			}
			if ( count( $found ) > 1 ) {
				$this->output( "error\n" );
				$this->error("Looks like the index has more than one identifier.  You should delete all\n" .
					"but the one of them currently active.  Here is the list:");
				foreach ( $found as $name ) {
					$this->error( $name );
				}
				die( 1 );
			}
			if ( $found ) {
				$identifier = substr( $found[0], strlen( $typeName ) + 1 );
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

	/**
	 * Get indecies with the named alias.  Doesn't use Elastica's status->getIndicesWithAlias because
	 * that feches index status from _every_ index.
	 *
	 * @var $alias string alias name
	 * @return array(\Elastica\Index) of index names
	 */
	private function getIndicesWithAlias( $alias ) {
		$client = Connection::getClient();
		$response = null;
		try {
			$response = $client->request( "/_alias/$alias" );
		} catch ( \Elastica\Exception\ResponseException $e ) {
			$transferInfo = $e->getResponse()->getTransferInfo();
			// 404 means the index alias doesn't exist which means no indexes have it.
			if ( $transferInfo['http_code'] === 404 ) {
				return array();
			}
			// If we don't have a 404 then this is still unexpected so rethrow the exception.
			throw $e;
		}
		if ( $response->hasError() ) {
			$this->error( 'Error fetching indecies with alias:  ' . $response->getError() );
		}
		$result = array();
		foreach ( $response->getData() as $name => $info ) {
			$result[] = new \Elastica\Index($client, $name);
		}
		return $result;
	}

	private static function parsePotentialPercent( $str ) {
		$result = floatval( $str );
		if ( strpos( $str, '%' ) === false ) {
			return $result;
		}
		return $result / 100;
	}
}

$maintClass = "CirrusSearch\UpdateOneSearchIndexConfig";
require_once RUN_MAINTENANCE_IF_MAIN;
