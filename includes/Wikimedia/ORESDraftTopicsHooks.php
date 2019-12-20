<?php

namespace CirrusSearch\Wikimedia;

use CirrusSearch\CirrusSearch;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use Config;
use MediaWiki\MediaWikiServices;
use SearchEngine;

class ORESDraftTopicsHooks {
	const FIELD_NAME = 'ores_drafttopics';
	const FIELD_SIMILARITY = 'ores_drafttopics_similarity';
	const FIELD_INDEX_ANALYZER = 'ores_drafttopics';
	const FIELD_SEARCH_ANALYZER = 'keyword';
	const WMF_EXTRA_FEATURES = 'CirrusSearchWMFExtraFeatures';
	const CONFIG_OPTIONS = 'ores_drafttopics';
	const BUILD_OPTION = 'build';
	const MAX_SCORE_OPTION = 'max_score';

	/**
	 * Configure the similarity needed for the draft topics field
	 * @param array &$similarity similarity settings to update
	 */
	public static function onCirrusSearchSimilarityConfig( array &$similarity ) {
		self::configureOresDraftTopicsSimilarity( $similarity,
			MediaWikiServices::getInstance()->getMainConfig() );
	}

	/**
	 * Visible for testing.
	 * @param array &$similarity similarity settings to update
	 * @param Config $config current configuration
	 */
	public static function configureOresDraftTopicsSimilarity(
		array &$similarity,
		Config $config
	) {
		if ( !self::canBuild( $config ) ) {
			return;
		}
		$maxScore = self::maxScore( $config );
		$similarity[self::FIELD_SIMILARITY] = [
			'type' => 'scripted',
			// no weight=>' script we do not want doc independent weighing
			'script' => [
				// apply boost close to docFreq to force int->float conversion
				'source' => "return (doc.freq*query.boost)/$maxScore;"
			]
		];
	}

	/**
	 * @param array &$fields array of field definitions to update
	 * @param SearchEngine $engine the search engine requesting field definitions
	 */
	public static function onSearchIndexFields( array &$fields, SearchEngine $engine ) {
		if ( !( $engine instanceof CirrusSearch ) ) {
			return;
		}
		self::configureOresDraftTopicsFieldMapping( $fields,
			MediaWikiServices::getInstance()->getMainConfig() );
	}

	/**
	 * Visible for testing
	 * @param \SearchIndexField[] &$fields array of field definitions to update
	 * @param Config $config the wiki configuration
	 */
	public static function configureOresDraftTopicsFieldMapping(
		array &$fields,
		Config $config
	) {
		if ( !self::canBuild( $config ) ) {
			return;
		}

		$fields[self::FIELD_NAME] = new ORESDraftTopicsField(
			self::FIELD_NAME,
			self::FIELD_NAME,
			self::FIELD_INDEX_ANALYZER,
			self::FIELD_SEARCH_ANALYZER,
			self::FIELD_SIMILARITY
		);
	}

	/**
	 * @param array &$config analysis settings to update
	 * @param AnalysisConfigBuilder $analysisConfigBuilder unneeded
	 */
	public static function onCirrusSearchAnalysisConfig( array &$config, AnalysisConfigBuilder $analysisConfigBuilder ) {
		self::configureOresDraftTopicsFieldAnalysis( $config,
			MediaWikiServices::getInstance()->getMainConfig() );
	}

	/**
	 * Visible for testing
	 * @param array &$analysisConfig panalysis settings to update
	 * @param Config $config the wiki configuration
	 */
	public static function configureOresDraftTopicsFieldAnalysis(
		array &$analysisConfig,
		Config $config
	) {
		if ( !self::canBuild( $config ) ) {
			return;
		}
		$maxScore = self::maxScore( $config );
		$analysisConfig['analyzer'][self::FIELD_INDEX_ANALYZER] = [
			'type' => 'custom',
			'tokenizer' => 'keyword',
			'filter' => [
				'ores_drafttopics_term_freq',
			]
		];
		$analysisConfig['filter']['ores_drafttopics_term_freq'] = [
			'type' => 'term_freq',
			// must be a char that never appears in the topic names/ids
			'split_char' => '|',
			// max score (clamped), we assume that orig_ores_score * 1000
			'max_tf' => $maxScore,
		];
	}

	private static function canBuild( Config $config ): bool {
		$extraFeatures = $config->get( self::WMF_EXTRA_FEATURES );
		$oresDraftTopicsOptions = $extraFeatures[self::CONFIG_OPTIONS] ?? [];
		return (bool)( $oresDraftTopicsOptions[self::BUILD_OPTION] ?? false );
	}

	private static function maxScore( Config $config ): int {
		$extraFeatures = $config->get( self::WMF_EXTRA_FEATURES );
		$oresDraftTopicsOptions = $extraFeatures[self::CONFIG_OPTIONS] ?? [];
		return (int)( $oresDraftTopicsOptions[self::MAX_SCORE_OPTION] ?? 1000 );
	}
}
