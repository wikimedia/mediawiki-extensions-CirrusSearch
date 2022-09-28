<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\ElasticaErrorHandler;
use CirrusSearch\Maintenance\Validators\MappingValidator;
use CirrusSearch\SearchConfig;
use CirrusSearch\Util;

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
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

/**
 * Update the elasticsearch configuration for this index.
 */
class UpdateOneSearchIndexConfig extends Maintenance {
	/**
	 * @var string
	 */
	private $indexSuffix;

	/**
	 * @var bool Are we going to blow the index away and start from scratch?
	 */
	private $startOver;

	/**
	 * @var int
	 */
	private $reindexChunkSize;

	/**
	 * @var string
	 */
	private $indexBaseName;

	/**
	 * @var string
	 */
	private $indexIdentifier;

	/**
	 * @var bool
	 */
	private $reindexAndRemoveOk;

	/**
	 * @var int number of scan slices to use when reindexing
	 */
	private $reindexSlices;

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
	 * @var array filtered analysis config
	 */
	private $analysisConfig;

	/**
	 * @var array list of available plugins
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
	 * @var int
	 */
	protected $refreshInterval;

	/**
	 * @var string
	 */
	protected $masterTimeout;

	/**
	 * @var array
	 */
	private $mapping = [];

	/**
	 * @var array
	 */
	private $similarityConfig;

	/**
	 * @var bool true if the analysis config can be optimized
	 */
	private $safeToOptimizeAnalysisConfig;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the configuration or contents of one search index. This always " .
			"operates on a single cluster." );
		$this->addOption( 'indexSuffix', 'Index to update.  Either content or general.', false, true );
		$this->addOption( 'indexType', 'BC form of --indexSuffix', false, true );
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
			"to the current time in seconds which should give you a unique identifier.", false, true );
		$maintenance->addOption( 'reindexAndRemoveOk', "If the alias is held by another index then " .
			"reindex all documents from that index (via the alias) to this one, swing the " .
			"alias to this index, and then remove other index.  Updates performed while this" .
			"operation is in progress will be queued up in the job queue.  Defaults to false." );
		$maintenance->addOption( 'reindexSlices', 'Number of slices to use in reindex. Roughly '
			. 'equivalent to the level of indexing parallelism. Defaults to number of shards.', false, true );
		$maintenance->addOption( 'reindexAcceptableCountDeviation', 'How much can the reindexed ' .
			'copy of an index is allowed to deviate from the current copy without triggering a ' .
			'reindex failure.  Defaults to 5%.', false, true );
		$maintenance->addOption( 'reindexChunkSize', 'Documents per shard to reindex in a batch.   ' .
			'Note when changing the number of shards that the old shard size is used, not the new ' .
			'one.  If you see many errors submitting documents in bulk but the automatic retry as ' .
			'singles works then lower this number.  Defaults to 100.', false, true );
		$maintenance->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
		$maintenance->addOption( 'debugCheckConfig', 'Print the configuration as it is checked ' .
			'to help debug unexpected configuration mismatches.' );
		$maintenance->addOption( 'justAllocation', 'Just validate the shard allocation settings.  Use ' .
			"when you need to apply new cache warmers but want to be sure that you won't apply any other " .
			'changes at an inopportune time.' );
		$maintenance->addOption( 'fieldsToDelete', 'List of of comma separated field names to delete ' .
			'while reindexing documents (defaults to empty)', false, true );
		$maintenance->addOption( 'justMapping', 'Just try to update the mapping.' );
	}

	public function execute() {
		global $wgLanguageCode,
			$wgCirrusSearchPhraseSuggestUseText,
			$wgCirrusSearchPrefixSearchStartsWithAnyWord,
			$wgCirrusSearchBannedPlugins,
			$wgCirrusSearchOptimizeIndexForExperimentalHighlighter,
			$wgCirrusSearchRefreshInterval,
			$wgCirrusSearchMasterTimeout;

		$this->disablePoolCountersAndLogging();

		$utils = new ConfigUtils( $this->getConnection()->getClient(), $this );

		$this->indexSuffix = $this->getBackCompatOption( 'indexSuffix', 'indexType' );
		$this->startOver = $this->getOption( 'startOver', false );
		$this->indexBaseName = $this->getOption( 'baseName',
			$this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ) );
		$this->reindexAndRemoveOk = $this->getOption( 'reindexAndRemoveOk', false );
		$this->reindexSlices = $this->getOption( 'reindexSlices', null );
		$this->reindexAcceptableCountDeviation = Util::parsePotentialPercent(
			$this->getOption( 'reindexAcceptableCountDeviation', '5%' ) );
		$this->reindexChunkSize = $this->getOption( 'reindexChunkSize', 100 );
		$this->printDebugCheckConfig = $this->getOption( 'debugCheckConfig', false );
		$this->langCode = $wgLanguageCode;
		$this->prefixSearchStartsWithAny = $wgCirrusSearchPrefixSearchStartsWithAnyWord;
		$this->phraseSuggestUseText = $wgCirrusSearchPhraseSuggestUseText;
		$this->bannedPlugins = $wgCirrusSearchBannedPlugins;
		$this->optimizeIndexForExperimentalHighlighter = $wgCirrusSearchOptimizeIndexForExperimentalHighlighter;
		$this->masterTimeout = $wgCirrusSearchMasterTimeout;
		$this->refreshInterval = $wgCirrusSearchRefreshInterval;

		if ( $this->indexSuffix === Connection::ARCHIVE_INDEX_SUFFIX ) {
			if ( !$this->getSearchConfig()->get( 'CirrusSearchEnableArchive' ) ) {
				$this->output( "Warning: Not allowing {$this->indexSuffix}, archives are disabled\n" );
				return true;
			}
			if ( !$this->getConnection()->getSettings()->isPrivateCluster() ) {
				$this->output( "Warning: Not allowing {$this->indexSuffix} on a non-private cluster\n" );
				return true;
			}
		}

		$this->initMappingConfigBuilder();

		try{
			$indexSuffixes = $this->getConnection()->getAllIndexSuffixes( null );
			if ( !in_array( $this->indexSuffix, $indexSuffixes ) ) {
				$this->fatalError( 'indexSuffix option must be one of ' .
					implode( ', ', $indexSuffixes ) );
			}

			$utils->checkElasticsearchVersion();
			$this->availablePlugins = $utils->scanAvailablePlugins( $this->bannedPlugins );

			if ( $this->getOption( 'justAllocation', false ) ) {
				$this->validateShardAllocation();
				return true;
			}

			if ( $this->getOption( 'justMapping', false ) ) {
				$this->validateMapping();
				return true;
			}

			$this->initAnalysisConfig();
			$this->indexIdentifier = $utils->pickIndexIdentifierFromOption(
				$this->getOption( 'indexIdentifier', 'current' ), $this->getIndexAliasName() );
			$this->validateIndex();
			$this->validateAnalyzers();
			$this->validateMapping();
			$this->validateAlias();
			$this->updateVersions();
		} catch ( \Elastica\Exception\Connection\HttpException $e ) {
			$message = $e->getMessage();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->fatalError( "Http error communicating with Elasticsearch:  $message.\n" );
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			$type = get_class( $e );
			$message = ElasticaErrorHandler::extractMessage( $e );
			/** @suppress PhanUndeclaredMethod ExceptionInterface has no methods */
			$trace = $e->getTraceAsString();
			$this->output( "\nUnexpected Elasticsearch failure.\n" );
			$this->fatalError( "Elasticsearch failed in an unexpected way. " .
				"This is always a bug in CirrusSearch.\n" .
				"Error type: $type\n" .
				"Message: $message\n" .
				"Trace:\n" . $trace );
		}

		return true;
	}

	/**
	 * @suppress PhanUndeclaredMethod runChild technically returns a
	 *  \Maintenance instance but only \CirrusSearch\Maintenance\Maintenance
	 *  classes have the done method. Just allow it since we know what type of
	 *  maint class is being created
	 */
	private function updateVersions() {
		$child = $this->runChild( Metastore::class );
		$child->done();
		$child->loadParamsAndArgs(
			null,
			array_merge( $this->parameters->getOptions(), [
				'index-version-basename' => $this->indexBaseName,
				'update-index-version' => true,
			] ),
			$this->parameters->getArgs()
		);
		$child->execute();
		$child->done();
	}

	private function validateIndex() {
		if ( $this->startOver ) {
			$this->createIndex( true, "Blowing away index to start over...\n" );
		} elseif ( !$this->getIndex()->exists() ) {
			$this->createIndex( false, "Creating index...\n" );
		}

		$this->validateIndexSettings();
	}

	/**
	 * @param bool $rebuild
	 * @param string $msg
	 */
	private function createIndex( $rebuild, $msg ) {
		global $wgCirrusSearchAllFields, $wgCirrusSearchExtraIndexSettings;

		$index = $this->getIndex();
		$indexCreator = new \CirrusSearch\Maintenance\IndexCreator(
			$index,
			new ConfigUtils( $index->getClient(), $this ),
			$this->analysisConfig,
			$this->similarityConfig
		);

		$this->outputIndented( $msg );

		$status = $indexCreator->createIndex(
			$rebuild,
			$this->getMaxShardsPerNode(),
			$this->getShardCount(),
			$this->getReplicaCount(),
			$this->refreshInterval,
			$this->getMergeSettings(),
			$wgCirrusSearchAllFields['build'],
			$wgCirrusSearchExtraIndexSettings
		);

		if ( !$status->isOK() ) {
			$this->fatalError( $status->getMessage()->text() );
		} else {
			$this->outputIndented( "Index created.\n" );
		}
	}

	/**
	 * @return \CirrusSearch\Maintenance\Validators\Validator[]
	 */
	private function getIndexSettingsValidators() {
		$validators = [];
		$validators[] = new \CirrusSearch\Maintenance\Validators\NumberOfShardsValidator(
			$this->getIndex(), $this->getShardCount(), $this );
		$validators[] = new \CirrusSearch\Maintenance\Validators\ReplicaRangeValidator(
			$this->getIndex(), $this->getReplicaCount(), $this );
		$validators[] = $this->getShardAllocationValidator();
		$validators[] = new \CirrusSearch\Maintenance\Validators\MaxShardsPerNodeValidator(
			$this->getIndex(), $this->getMaxShardsPerNode(), $this );
		return $validators;
	}

	private function validateIndexSettings() {
		$validators = $this->getIndexSettingsValidators();
		foreach ( $validators as $validator ) {
			$status = $validator->validate();
			if ( !$status->isOK() ) {
				$this->fatalError( $status->getMessage()->text() );
			}
		}
	}

	private function validateAnalyzers() {
		$validator = new \CirrusSearch\Maintenance\Validators\AnalyzersValidator(
			$this->getIndex(), $this->analysisConfig, $this );
		$validator->printDebugCheckConfig( $this->printDebugCheckConfig );
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->fatalError( $status->getMessage()->text() );
		}
	}

	private function validateMapping() {
		$validator = new MappingValidator(
			$this->getIndex(),
			$this->masterTimeout,
			$this->optimizeIndexForExperimentalHighlighter,
			$this->availablePlugins,
			$this->mapping,
			$this
		);
		$validator->printDebugCheckConfig( $this->printDebugCheckConfig );
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->fatalError( $status->getMessage()->text() );
		}
	}

	private function validateAlias() {
		$this->outputIndented( "Validating aliases...\n" );
		// Since validate the specific alias first as that can cause reindexing
		// and we want the all index to stay with the old index during reindexing
		$this->validateSpecificAlias();

		if ( $this->indexSuffix !== Connection::ARCHIVE_INDEX_SUFFIX ) {
			// Do not add the archive index to the global alias
			$this->validateAllAlias();
		}
	}

	/**
	 * Validate the alias that is just for this index's type.
	 */
	private function validateSpecificAlias() {
		$connection = $this->getConnection();

		$reindexer = new Reindexer(
			$this->getSearchConfig(),
			$connection,
			$connection,
			$this->getIndex(),
			$this->getOldIndex(),
			$this,
			array_filter( explode( ',', $this->getOption( 'fieldsToDelete', '' ) ) )
		);

		$validator = new \CirrusSearch\Maintenance\Validators\SpecificAliasValidator(
			$this->getConnection()->getClient(),
			$this->getIndexAliasName(),
			$this->getSpecificIndexName(),
			$this->startOver,
			$reindexer,
			[
				$this->reindexSlices,
				$this->reindexChunkSize,
				$this->reindexAcceptableCountDeviation
			],
			$this->getIndexSettingsValidators(),
			$this->reindexAndRemoveOk,
			$this
		);
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->fatalError( $status->getMessage()->text() );
		}
	}

	public function validateAllAlias() {
		$validator = new \CirrusSearch\Maintenance\Validators\IndexAllAliasValidator(
			$this->getConnection()->getClient(),
			$this->getIndexName(),
			$this->getSpecificIndexName(),
			$this->startOver,
			$this->getIndexAliasName(),
			$this
		);
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->fatalError( $status->getMessage()->text() );
		}
	}

	/**
	 * @return \CirrusSearch\Maintenance\Validators\Validator
	 */
	private function getShardAllocationValidator() {
		global $wgCirrusSearchIndexAllocation;
		return new \CirrusSearch\Maintenance\Validators\ShardAllocationValidator(
			$this->getIndex(), $wgCirrusSearchIndexAllocation, $this );
	}

	protected function validateShardAllocation() {
		$validator = $this->getShardAllocationValidator();
		$status = $validator->validate();
		if ( !$status->isOK() ) {
			$this->fatalError( $status->getMessage()->text() );
		}
	}

	/**
	 * @param string $langCode
	 * @param array $availablePlugins
	 * @return AnalysisConfigBuilder
	 */
	private function pickAnalyzer( $langCode, array $availablePlugins = [] ) {
		$analysisConfigBuilder = new \CirrusSearch\Maintenance\AnalysisConfigBuilder(
			$langCode, $availablePlugins );
		$this->outputIndented( 'Picking analyzer...' .
								$analysisConfigBuilder->getDefaultTextAnalyzerType( $langCode ) .
								"\n" );
		return $analysisConfigBuilder;
	}

	/**
	 * @throws \ConfigException
	 */
	protected function initMappingConfigBuilder() {
		$configFlags = 0;
		if ( $this->prefixSearchStartsWithAny ) {
			$configFlags |= MappingConfigBuilder::PREFIX_START_WITH_ANY;
		}
		if ( $this->phraseSuggestUseText ) {
			$configFlags |= MappingConfigBuilder::PHRASE_SUGGEST_USE_TEXT;
		}
		switch ( $this->indexSuffix ) {
			case Connection::ARCHIVE_DOC_TYPE:
				$mappingConfigBuilder = new ArchiveMappingConfigBuilder( $this->optimizeIndexForExperimentalHighlighter, $configFlags );
				break;
			default:
				$mappingConfigBuilder = new MappingConfigBuilder( $this->optimizeIndexForExperimentalHighlighter, $configFlags );
		}
		$this->mapping = $mappingConfigBuilder->buildConfig();
		$this->safeToOptimizeAnalysisConfig = $mappingConfigBuilder->canOptimizeAnalysisConfig();
	}

	/**
	 * @return \Elastica\Index being updated
	 */
	public function getIndex() {
		return $this->getConnection()->getIndex(
			$this->indexBaseName, $this->indexSuffix, $this->indexIdentifier );
	}

	/**
	 * @return string name of the index being updated
	 */
	protected function getSpecificIndexName() {
		return $this->getConnection()->getIndexName(
			$this->indexBaseName, $this->indexSuffix, $this->indexIdentifier );
	}

	/**
	 * @return string name of the index type being updated
	 */
	protected function getIndexAliasName() {
		return $this->getConnection()->getIndexName( $this->indexBaseName, $this->indexSuffix );
	}

	/**
	 * @return string
	 */
	protected function getIndexName() {
		return $this->getConnection()->getIndexName( $this->indexBaseName );
	}

	/**
	 * @return \Elastica\Index
	 */
	protected function getOldIndex() {
		return $this->getConnection()->getIndex( $this->indexBaseName, $this->indexSuffix );
	}

	/**
	 * Get the merge settings for this index.
	 * @return array
	 */
	private function getMergeSettings() {
		global $wgCirrusSearchMergeSettings;

		return $wgCirrusSearchMergeSettings[$this->indexSuffix]
			// If there aren't configured merge settings for this index type
			// default to the content type.
			?? $wgCirrusSearchMergeSettings['content']
			// It's also fine to not specify merge settings.
			?? [];
	}

	/**
	 * @return int Number of shards this index should have
	 */
	private function getShardCount() {
		return $this->getConnection()->getSettings()->getShardCount( $this->indexSuffix );
	}

	/**
	 * @return string Number of replicas this index should have. May be a range such as '0-2'
	 */
	private function getReplicaCount() {
		return $this->getConnection()->getSettings()->getReplicaCount( $this->indexSuffix );
	}

	/**
	 * @return int Maximum number of shards that can be allocated on a single elasticsearch
	 *  node. -1 for unlimited.
	 */
	private function getMaxShardsPerNode() {
		return $this->getConnection()->getSettings()->getMaxShardsPerNode( $this->indexSuffix );
	}

	private function initAnalysisConfig() {
		$analysisConfigBuilder = $this->pickAnalyzer( $this->langCode, $this->availablePlugins );

		$this->analysisConfig = $analysisConfigBuilder->buildConfig();
		if ( $this->safeToOptimizeAnalysisConfig ) {
			$filter = new AnalysisFilter();
			list( $this->analysisConfig, $this->mapping ) = $filter
				->filterAnalysis( $this->analysisConfig, $this->mapping );
		}
		$this->similarityConfig = $analysisConfigBuilder->buildSimilarityConfig();
	}
}

$maintClass = UpdateOneSearchIndexConfig::class;
require_once RUN_MAINTENANCE_IF_MAIN;
