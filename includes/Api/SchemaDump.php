<?php

namespace CirrusSearch\Api;

use CirrusSearch\Connection;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\ArchiveMappingConfigBuilder;
use CirrusSearch\Maintenance\ConfigUtils;
use CirrusSearch\Maintenance\MappingConfigBuilder;
use CirrusSearch\Maintenance\NullPrinter;
use CirrusSearch\Maintenance\SuggesterAnalysisConfigBuilder;
use CirrusSearch\Maintenance\SuggesterMappingConfigBuilder;
use CirrusSearch\SearchConfig;
use MediaWiki\Api\ApiBase;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Dumps CirrusSearch mappings for easy viewing.
 *
 * @license GPL-2.0-or-later
 */
class SchemaDump extends ApiBase {
	use ApiTrait;

	public function execute() {
		$build = $this->getParameter( 'build' );
		$conn = $this->getCirrusConnection();
		$indexPrefix = $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME );

		// Get all index suffixes to process
		$indexSuffixes = $conn->getAllIndexSuffixes( null );

		if ( $build ) {
			try {
				$this->buildFromCode( $conn, $indexPrefix, $indexSuffixes );
			} catch ( \InvalidArgumentException $e ) {
				$this->dieWithException( $e );
			}
		} else {
			$this->fetchFromCluster( $conn, $indexPrefix, $indexSuffixes );
		}
	}

	/**
	 * Fetch schema (settings and mappings) from live cluster indices
	 *
	 * @param Connection $conn CirrusSearch connection
	 * @param string $indexPrefix Index prefix (typically wiki ID)
	 * @param array $indexSuffixes Index suffixes to process
	 */
	private function fetchFromCluster( Connection $conn, string $indexPrefix, array $indexSuffixes ): void {
		foreach ( $indexSuffixes as $suffix ) {
			$index = $conn->getIndex( $indexPrefix, $suffix );

			if ( !$index->exists() ) {
				continue;
			}

			$settings = $index->getSettings()->get();
			$mappings = $index->getMapping();

			$this->getResult()->addValue(
				null,
				$suffix,
				[
					'settings' => [ 'index' => $settings ],
					'mappings' => $mappings
				]
			);
		}

		// Handle completion suggester if enabled
		if ( $this->getSearchConfig()->isCompletionSuggesterEnabled() ) {
			$index = $conn->getIndex( $indexPrefix, Connection::TITLE_SUGGEST_INDEX_SUFFIX );
			if ( $index->exists() ) {
				$settings = $index->getSettings()->get();
				$mappings = $index->getMapping();

				$this->getResult()->addValue(
					null,
					Connection::TITLE_SUGGEST_INDEX_SUFFIX,
					[
						'settings' => [ 'index' => $settings ],
						'mappings' => $mappings
					]
				);
			}
		}
	}

	/**
	 * Build schema (settings and mappings) from code and configuration
	 *
	 * @param Connection $conn CirrusSearch connection
	 * @param string $indexPrefix Index prefix (typically wiki ID)
	 * @param array $indexSuffixes Index suffixes to process
	 */
	private function buildFromCode( Connection $conn, string $indexPrefix, array $indexSuffixes ): void {
		$buildContext = $this->getBuildContext( $conn );

		foreach ( $indexSuffixes as $suffix ) {
			$schema = $this->buildSchemaForIndex( $suffix, $buildContext );
			$indexName = $indexPrefix . '_' . $suffix;

			$this->getResult()->addValue( null, $suffix, $schema );
		}

		// Handle completion suggester if enabled
		if ( $this->getSearchConfig()->isCompletionSuggesterEnabled() ) {
			$suffix = Connection::TITLE_SUGGEST_INDEX_SUFFIX;
			$schema = $this->buildSchemaForIndex( $suffix, $buildContext );

			$this->getResult()->addValue( null, $suffix, $schema );
		}
	}

	/**
	 * Gather all context needed for building schema from code
	 *
	 * @param Connection $conn CirrusSearch connection
	 * @return array Build context with language code, plugins, flags, settings
	 */
	private function getBuildContext( Connection $conn ): array {
		$config = $this->getSearchConfig();

		// Get language code
		$langCode = $config->get( 'LanguageCode' );

		// Check if plugins were provided via API parameter
		$pluginsParam = $this->getParameter( 'plugins' );

		$utils = new ConfigUtils( $conn->getClient(), new NullPrinter() );
		$bannedPlugins = $config->get( 'CirrusSearchBannedPlugins' );
		if ( $pluginsParam !== null ) {
			$plugins = $utils->removeBannedPlugins( $pluginsParam, $bannedPlugins );
		} else {
			// Fall back to scanning cluster
			$pluginStatus = $utils->scanAvailablePlugins( $bannedPlugins );
			if ( !$pluginStatus->isOK() ) {
				$this->dieStatus( $pluginStatus );
			}
			$plugins = $pluginStatus->getValue();
		}

		// Get config flags
		$flags = 0;
		if ( $config->get( 'CirrusSearchPrefixSearchStartsWithAnyWord' ) ) {
			$flags |= MappingConfigBuilder::PREFIX_START_WITH_ANY;
		}
		if ( $config->get( 'CirrusSearchPhraseSuggestUseText' ) ) {
			$flags |= MappingConfigBuilder::PHRASE_SUGGEST_USE_TEXT;
		}

		$optimizeForHighlighter = $config->get( 'CirrusSearchOptimizeIndexForExperimentalHighlighter' );

		// Get replica and refresh settings
		$replicas = $config->get( 'CirrusSearchReplicas' );
		$refreshInterval = $config->get( 'CirrusSearchRefreshInterval' );

		return [
			'langCode' => $langCode,
			'plugins' => $plugins,
			'flags' => $flags,
			'optimizeForHighlighter' => $optimizeForHighlighter,
			'replicas' => $replicas,
			'refreshInterval' => $refreshInterval,
			'config' => $config,
			'conn' => $conn,
		];
	}

	/**
	 * Build complete schema for a specific index type
	 *
	 * @param string $indexSuffix Index suffix (content, general, archive, titlesuggest)
	 * @param array $context Build context from getBuildContext()
	 * @return array Schema with 'settings' and 'mappings' keys
	 */
	private function buildSchemaForIndex( string $indexSuffix, array $context ): array {
		$config = $context['config'];

		// Select appropriate builders based on index type
		if ( $indexSuffix === Connection::TITLE_SUGGEST_INDEX_SUFFIX ) {
			$analysisBuilder = new SuggesterAnalysisConfigBuilder(
				$context['langCode'],
				$context['plugins'],
				$config
			);
			$mappingBuilder = new SuggesterMappingConfigBuilder( $config );
		} elseif ( $indexSuffix === Connection::ARCHIVE_INDEX_SUFFIX ) {
			$analysisBuilder = new AnalysisConfigBuilder(
				$context['langCode'],
				$context['plugins'],
				$config
			);
			$mappingBuilder = new ArchiveMappingConfigBuilder(
				$context['optimizeForHighlighter'],
				$context['plugins'],
				$context['flags'],
				$config
			);
		} else {
			// Content or general index
			$analysisBuilder = new AnalysisConfigBuilder(
				$context['langCode'],
				$context['plugins'],
				$config
			);
			$mappingBuilder = new MappingConfigBuilder(
				$context['optimizeForHighlighter'],
				$context['plugins'],
				$context['flags'],
				$config
			);
		}

		// Build analysis and mappings
		$analysisConfig = $analysisBuilder->buildConfig();
		$mappings = $mappingBuilder->buildConfig();

		// Build complete settings structure
		$settings = $this->buildCompleteSettings( $analysisConfig, $indexSuffix, $context );

		return [
			'settings' => $settings,
			'mappings' => $mappings
		];
	}

	/**
	 * Construct complete settings structure matching IndexCreator
	 *
	 * @param array $analysisConfig Analysis configuration (analyzers, tokenizers, filters)
	 * @param string $indexSuffix Index suffix for getting type-specific settings
	 * @param array $context Build context from getBuildContext()
	 * @return array Complete settings structure
	 */
	private function buildCompleteSettings( array $analysisConfig, string $indexSuffix, array $context ): array {
		$config = $context['config'];
		$conn = $context['conn'];

		// Get shard count for this specific index type
		$shardCount = $conn->getSettings()->getShardCount( $indexSuffix );

		// Get replica count for this index type
		$replicas = $context['replicas'];
		$replicaCount = is_array( $replicas ) && isset( $replicas[$indexSuffix] )
			? $replicas[$indexSuffix]
			: '0-2';

		// Build similarity config
		$analysisBuilder = new AnalysisConfigBuilder(
			$context['langCode'],
			$context['plugins'],
			$config
		);
		$similarityConfig = $analysisBuilder->buildSimilarityConfig();

		// Base settings structure
		$indexSettings = [
			'number_of_shards' => $shardCount,
			'auto_expand_replicas' => $replicaCount,
			'refresh_interval' => $context['refreshInterval'] . 's',
			'analysis' => $analysisConfig,
			'query' => [
				'default_field' => 'all'
			],
		];

		// Add similarity if present
		if ( $similarityConfig ) {
			$indexSettings['similarity'] = $similarityConfig;
		}

		// Add merge settings if configured
		$mergeSettings = $config->get( 'CirrusSearchMergeSettings' );
		if ( is_array( $mergeSettings ) && isset( $mergeSettings[$indexSuffix] ) ) {
			$indexSettings['merge'] = [ 'policy' => $mergeSettings[$indexSuffix] ];
		}

		// Wrap in 'index' key
		$settings = [ 'index' => $indexSettings ];

		// Add extra settings if configured
		$extraSettings = $config->get( 'CirrusSearchExtraIndexSettings' );
		if ( is_array( $extraSettings ) ) {
			$settings = array_merge( $settings, $extraSettings );
		}

		return $settings;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'build' => [
				ParamValidator::PARAM_DEFAULT => false,
				ParamValidator::PARAM_TYPE => 'boolean',
			],
			'plugins' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => null,
				ParamValidator::PARAM_ALLOW_DUPLICATES => false,
			],
		];
	}

	/**
	 * Mark as internal. This isn't meant to be used by normal api users
	 * @return bool
	 */
	public function isInternal() {
		return true;
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=cirrus-schema-dump' =>
				'apihelp-cirrus-schema-dump-example',
			'action=cirrus-schema-dump&build=true' =>
				'apihelp-cirrus-schema-dump-example-build',
			'action=cirrus-schema-dump&build=true&plugins=analysis-icu|extra-analysis-textify' =>
				'apihelp-cirrus-schema-dump-example-build-plugins',
		];
	}

}
