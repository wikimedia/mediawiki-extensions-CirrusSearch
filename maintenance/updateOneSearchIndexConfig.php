<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\Util;
use ConfigFactory;
use Elastica;

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
require_once( __DIR__ . '/../includes/Maintenance/Maintenance.php' );

/**
 * Update the elasticsearch configuration for this index.
 */
class UpdateOneSearchIndexConfig extends Maintenance {
	private $indexType;

	// Are we going to blow the index away and start from scratch?
	private $startOver;

	private $reindexChunkSize;
	private $reindexRetryAttempts;

	private $indexBaseName;
	private $indexIdentifier;
	private $reindexAndRemoveOk;

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
	private $phraseSuggestUseText;

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
	 * @var AnalysisConfigBuilder the builder for analysis config
	 */
	private $analysisConfigBuilder;

	/**
	 * @var array(String) list of available plugins
	 */
	private $availablePlugins;

	/**
	 * @var array
	 */
	protected $bannedPlugins;

	/**
	 * @var bool
	 */
	protected $optimizeIndexForExperimentalHighlighter;

	/**
	 * @var array
	 */
	protected $maxShardsPerNode;

	/**
	 * @var int
	 */
	protected $refreshInterval;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the configuration or contents of one search index. This always operates on a single cluster." );
		$this->addOption( 'indexType', 'Index to update.  Either content or general.', true, true );
		self::addSharedOptions( $this );
	}

	/**
	 * @param Maintenance $maintenance
	 */
	public static function addSharedOptions( $maintenance ) {
		$maintenance->addOption( 'startOver', 'Blow away the identified index and rebuild it with ' .
			'no data.' );
		$maintenance->addOption( 'indexIdentifier', "Set the identifier of the index to work on.  " .
			"You'll need this if you have an index in production serving queries and you have " .
			"to alter some portion of its configuration that cannot safely be done without " .
			"rebuilding it.  Once you specify a new indexIdentifier for this wiki you'll have to " .
			"run this script with the same identifier each time.  Defaults to 'current' which " .
			"infers the currently in use identifier.  You can also use 'now' to set the identifier " .
			"to the current time in seconds which should give you a unique identifier.", false, true);
		$maintenance->addOption( 'reindexAndRemoveOk', "If the alias is held by another index then " .
			"reindex all documents from that index (via the alias) to this one, swing the " .
			"alias to this index, and then remove other index.  Updates performed while this".
			"operation is in progress will be queued up in the job queue.  Defaults to false." );
		$maintenance->addOption( 'reindexProcesses', 'Number of processes to use in reindex.  ' .
			'Not supported on Windows.  Defaults to 1 on Windows and 5 otherwise.', false, true );
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
			'to help debug unexpected configuration mismatches.' );
		$maintenance->addOption( 'justCacheWarmers', 'Just validate that the cache warmers are correct ' .
			'and perform no additional checking.  Use when you need to apply new cache warmers but ' .
			"want to be sure that you won't apply any other changes at an inopportune time." );
		$maintenance->addOption( 'justAllocation', 'Just validate the shard allocation settings.  Use ' .
			"when you need to apply new cache warmers but want to be sure that you won't apply any other " .
			'changes at an inopportune time.' );
		$maintenance->addOption( 'justMapping', 'Just try to update the mapping.' );
	}

	public function execute() {
		global $wgPoolCounterConf,
			$wgLanguageCode,
			$wgCirrusSearchPhraseSuggestUseText,
			$wgCirrusSearchPrefixSearchStartsWithAnyWord,
			$wgCirrusSearchBannedPlugins,
			$wgCirrusSearchOptimizeIndexForExperimentalHighlighter,
			$wgCirrusSearchMaxShardsPerNode,
			$wgCirrusSearchRefreshInterval;

		// Make sure we don't flood the pool counter
		unset( $wgPoolCounterConf['CirrusSearch-Search'] );

		// Set the timeout for maintenance actions
		$this->setConnectionTimeout();

		$utils = new ConfigUtils( $this->getConnection()->getClient(), $this );

		$this->indexType = $this->getOption( 'indexType' );
		$this->startOver = $this->getOption( 'startOver', false );
		$this->indexBaseName = $this->getOption( 'baseName', wfWikiId() );
		$this->reindexAndRemoveOk = $this->getOption( 'reindexAndRemoveOk', false );
		$this->reindexProcesses = $this->getOption( 'reindexProcesses', wfIsWindows() ? 1 : 5 );
		$this->reindexAcceptableCountDeviation = Util::parsePotentialPercent(
			$this->getOption( 'reindexAcceptableCountDeviation', '5%' ) );
		$this->reindexChunkSize = $this->getOption( 'reindexChunkSize', 100 );
		$this->reindexRetryAttempts = $this->getOption( 'reindexRetryAttempts', 5 );
		$this->printDebugCheckConfig = $this->getOption( 'debugCheckConfig', false );
		$this->langCode = $wgLanguageCode;
		$this->prefixSearchStartsWithAny = $wgCirrusSearchPrefixSearchStartsWithAnyWord;
		$this->phraseSuggestUseText = $wgCirrusSearchPhraseSuggestUseText;
		$this->bannedPlugins = $wgCirrusSearchBannedPlugins;
		$this->optimizeIndexForExperimentalHighlighter = $wgCirrusSearchOptimizeIndexForExperimentalHighlighter;
		$this->maxShardsPerNode = isset( $wgCirrusSearchMaxShardsPerNode[ $this->indexType ] ) ? $wgCirrusSearchMaxShardsPerNode[ $this->indexType ] : 'unlimited';
		$this->refreshInterval = $wgCirrusSearchRefreshInterval;

		try{
			$indexTypes = $this->getConnection()->getAllIndexTypes();
			if ( !in_array( $this->indexType, $indexTypes ) ) {
				$this->error( 'indexType option must be one of ' .
					implode( ', ', $indexTypes ), 1 );
			}

			$utils->checkElasticsearchVersion();
			$this->availablePlugins = $utils->scanAvailablePlugins( $this->bannedPlugins );

			if ( $this->getOption( 'justCacheWarmers', false ) ) {
				$this->validateCacheWarmers();
				return;
			}

			if ( $this->getOption( 'justAllocation', false ) ) {
				$this->validateShardAllocation();
				return;
			}

			if ( $this->getOption( 'justMapping', false ) ) {
				$this->validateMapping();
				return;
			}

			$this->indexIdentifier = $utils->pickIndexIdentifierFromOption( $this->getOption( 'indexIdentifier', 'current' ), $this->getIndexTypeName() );
			$this->analysisConfigBuilder = $this->pickAnalyzer( $this->langCode, $this->availablePlugins );
			$this->validateIndex();
			$this->validateAnalyzers();
			$this->validateMapping();
			$this->validateCacheWarmers();
			$this->validateAlias();
			$this->updateVersions();
			$this->indexNamespaces();
		} catch ( \Elastica\Exception\Connection\HttpException $e ) {
			$message = $e->getMessage();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->error( "Http error communicating with Elasticsearch:  $message.\n", 1 );
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$type = get_class( $e );
			$message = ElasticsearchIntermediary::extractMessage( $e );
			$trace = $e->getTraceAsString();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->error( "Elasticsearch failed in an unexpected way.  This is always a bug in CirrusSearch.\n" .
				"Error type: $type\n" .
				"Message: $message\n" .
				"Trace:\n" . $trace, 1 );
		}
	}

	private function updateVersions() {
		$child = $this->runChild( 'CirrusSearch\Maintenance\UpdateVersionIndex' );
		$child->mOptions['baseName'] = $this->indexBaseName;
		$child->mOptions['update'] = true;
		$child->execute();
		$child->done();
	}

	private function indexNamespaces() {
		// Only index namespaces if we're doing the general index
		if ( $this->indexType === 'general' ) {
			$child = $this->runChild( 'CirrusSearch\Maintenance\IndexNamespaces' );
			$child->execute();
			$child->done();
		}
	}

	private function validateIndex() {
		global $wgCirrusSearchAllFields;

		// $this->startOver || !$this->getIndex()->exists() are the conditions
		// under which a new index will be created
		$this->tooFewReplicas = ( $this->startOver || !$this->getIndex()->exists() ) && $this->reindexAndRemoveOk;

		$validator = new \CirrusSearch\Maintenance\Validators\IndexValidator(
			$this->getIndex(),
			$this->startOver,
			$this->maxShardsPerNode,
			$this->getShardCount(),
			$this->getReplicaCount(),
			$this->refreshInterval,
			$wgCirrusSearchAllFields['build'],
			$this->analysisConfigBuilder,
			$this->getMergeSettings(),
			$this
		);
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->error( $status->getMessage()->text(), 1 );
		}

		$this->validateIndexSettings();
	}

	/**
	 * @return \CirrusSearch\Maintenance\Validators\Validator[]
	 */
	private function getIndexSettingsValidators() {
		$validators = array();
		$validators[] = new \CirrusSearch\Maintenance\Validators\NumberOfShardsValidator( $this->getIndex(), $this->getShardCount(), $this );
		$validators[] = new \CirrusSearch\Maintenance\Validators\ReplicaRangeValidator( $this->getIndex(), $this->getReplicaCount(), $this );
		$validators[] = $this->getShardAllocationValidator();
		$validators[] = new \CirrusSearch\Maintenance\Validators\MaxShardsPerNodeValidator( $this->getIndex(), $this->indexType, $this->maxShardsPerNode, $this );
		return $validators;
	}

	private function validateIndexSettings() {
		$validators = $this->getIndexSettingsValidators();
		foreach ( $validators as $validator ) {
			$status = $validator->validate();
			if ( !$status->isOK() ) {
				$this->error( $status->getMessage()->text(), 1 );
			}
		}
	}

	private function validateAnalyzers() {
		$validator = new \CirrusSearch\Maintenance\Validators\AnalyzersValidator( $this->getIndex(), $this->analysisConfigBuilder, $this );
		$validator->printDebugCheckConfig( $this->printDebugCheckConfig );
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->error( $status->getMessage()->text(), 1 );
		}
	}

	private function validateMapping() {
		$validator = new \CirrusSearch\Maintenance\Validators\MappingValidator(
			$this->getIndex(),
			$this->optimizeIndexForExperimentalHighlighter,
			$this->availablePlugins,
			$this->getMappingConfig(),
			array( 'page' => $this->getPageType(), 'namespace' => $this->getNamespaceType() ),
			$this
		);
		$validator->printDebugCheckConfig( $this->printDebugCheckConfig );
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->error( $status->getMessage()->text(), 1 );
		}
	}

	private function validateAlias() {
		$this->outputIndented( "Validating aliases...\n" );
		// Since validate the specific alias first as that can cause reindexing
		// and we want the all index to stay with the old index during reindexing
		$this->validateSpecificAlias();
		$this->validateAllAlias();
	}

	/**
	 * Validate the alias that is just for this index's type.
	 */
	private function validateSpecificAlias() {
		global $wgCirrusSearchMaintenanceTimeout;

		$connection = $this->getConnection();

		$reindexer = new Reindexer(
			$connection,
			$connection,
			array( $this->getPageType() ),
			array( $this->getOldPageType() ),
			$this->getShardCount(),
			$this->getReplicaCount(),
			$wgCirrusSearchMaintenanceTimeout,
			$this->getMergeSettings(),
			$this->getMappingConfig(),
			$this
		);

		$validator = new \CirrusSearch\Maintenance\Validators\SpecificAliasValidator(
			$this->getConnection()->getClient(),
			$this->getIndexTypeName(),
			$this->getSpecificIndexName(),
			$this->startOver,
			$reindexer,
			array( $this->reindexProcesses, $this->refreshInterval, $this->reindexRetryAttempts, $this->reindexChunkSize, $this->reindexAcceptableCountDeviation ),
			$this->getIndexSettingsValidators(),
			$this->reindexAndRemoveOk,
			$this->tooFewReplicas,
			$this
		);
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->error( $status->getMessage()->text(), 1 );
		}
	}

	public function validateAllAlias() {
		$validator = new \CirrusSearch\Maintenance\Validators\IndexAllAliasValidator( $this->getConnection()->getClient(),
			$this->getIndexName(), $this->getSpecificIndexName(), $this->startOver, $this->getIndexTypeName(), $this );
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->error( $status->getMessage()->text(), 1 );
		}

		if ( $this->tooFewReplicas ) {
			$this->validateIndexSettings();
		}
	}

	protected function validateCacheWarmers() {
		global $wgCirrusSearchMainPageCacheWarmer, $wgCirrusSearchCacheWarmers;

		if ( $wgCirrusSearchMainPageCacheWarmer ) {
			$wgCirrusSearchCacheWarmers['content'][] = \Title::newMainPage()->getText();
		}
		$cacheWarmers = isset( $wgCirrusSearchCacheWarmers[$this->indexType] ) ? $wgCirrusSearchCacheWarmers[$this->indexType] : array();

		$warmers = new \CirrusSearch\Maintenance\Validators\CacheWarmersValidator( $this->indexType, $this->getPageType(), $cacheWarmers, $this );
		$status = $warmers->validate();
		if ( !$status->isOK() ) {
			$this->error( $status->getMessage()->text(), 1 );
		}
	}

	/**
	 * @return \CirrusSearch\Maintenance\Validators\Validator
	 */
	private function getShardAllocationValidator() {
		global $wgCirrusSearchIndexAllocation;
		return new \CirrusSearch\Maintenance\Validators\ShardAllocationValidator( $this->getIndex(), $wgCirrusSearchIndexAllocation, $this );
	}

	protected function validateShardAllocation() {
		$validator = $this->getShardAllocationValidator();
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->error( $status->getMessage()->text(), 1 );
		}
	}

	/**
	 * @param string $langCode
	 * @param array $availablePlugins
	 * @return AnalysisConfigBuilder
	 */
	private function pickAnalyzer( $langCode, array $availablePlugins = array() ) {
		$analysisConfigBuilder = new \CirrusSearch\Maintenance\AnalysisConfigBuilder( $langCode, $availablePlugins );
		$this->outputIndented( 'Picking analyzer...' .
			$analysisConfigBuilder->getDefaultTextAnalyzerType() . "\n" );
		return $analysisConfigBuilder;
	}

	/**
	 * @return array
	 */
	protected function getMappingConfig() {
		$builder = new MappingConfigBuilder( $this->optimizeIndexForExperimentalHighlighter );
		$configFlags = 0;
		if ( $this->prefixSearchStartsWithAny ) {
			$configFlags |= MappingConfigBuilder::PREFIX_START_WITH_ANY;
		}
		if ( $this->phraseSuggestUseText ) {
			$configFlags |= MappingConfigBuilder::PHRASE_SUGGEST_USE_TEXT;
		}
		return $builder->buildConfig( $configFlags );
	}

	/**
	 * @return \Elastica\Index being updated
	 */
	public function getIndex() {
		return $this->getConnection()->getIndex( $this->indexBaseName, $this->indexType, $this->indexIdentifier );
	}

	/**
	 * @return string name of the index being updated
	 */
	protected function getSpecificIndexName() {
		return $this->getConnection()->getIndexName( $this->indexBaseName, $this->indexType, $this->indexIdentifier );
	}

	/**
	 * @return string name of the index type being updated
	 */
	protected function getIndexTypeName() {
		return $this->getConnection()->getIndexName( $this->indexBaseName, $this->indexType );
	}

	/**
	 * @return string
	 */
	protected function getIndexName() {
		return $this->getConnection()->getIndexName( $this->indexBaseName );
	}

	/**
	 * Get the page type being updated by the search config.
	 *
	 * @return Elastica\Type
	 */
	protected function getPageType() {
		return $this->getIndex()->getType( Connection::PAGE_TYPE_NAME );
	}

	/**
	 * Get the namespace type being updated by the search config.
	 *
	 * @return Elastica\Type
	 */
	protected function getNamespaceType() {
		return $this->getIndex()->getType( Connection::NAMESPACE_TYPE_NAME );
	}

	/**
	 * @return Elastica\Type
	 */
	protected function getOldPageType() {
		return $this->getConnection()->getPageType( $this->indexBaseName, $this->indexType );
	}

	protected function setConnectionTimeout() {
		global $wgCirrusSearchMaintenanceTimeout;
		$this->getConnection()->setTimeout( $wgCirrusSearchMaintenanceTimeout );
	}

	/**
	 * Get the merge settings for this index.
	 */
	private function getMergeSettings() {
		global $wgCirrusSearchMergeSettings;

		if ( isset( $wgCirrusSearchMergeSettings[ $this->indexType ] ) ) {
			return $wgCirrusSearchMergeSettings[ $this->indexType ];
		}
		// If there aren't configured merge settings for this index type default to the content type.
		return $wgCirrusSearchMergeSettings[ 'content' ];
	}

	private function getShardCount() {
		return $this->getConnection()->getSettings()->getShardCount( $this->indexType );
	}

	private function getReplicaCount() {
		return $this->getConnection()->getSettings()->getReplicaCount( $this->indexType );
	}
}

$maintClass = "CirrusSearch\Maintenance\UpdateOneSearchIndexConfig";
require_once RUN_MAINTENANCE_IF_MAIN;
