<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusSearch;
use CirrusSearch\Hooks\CirrusSearchAddQueryFeaturesHook;
use CirrusSearch\Hooks\CirrusSearchAnalysisConfigHook;
use CirrusSearch\Hooks\CirrusSearchSimilarityConfigHook;
use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Query\ArticlePredictionKeyword;
use CirrusSearch\Query\HasRecommendationFeature;
use CirrusSearch\SearchConfig;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Search\Hook\SearchIndexFieldsHook;

/**
 * Functionality related to the weighted_tags search feature.
 * @package CirrusSearch\Search
 * @see ArticlePredictionKeyword
 */
class WeightedTagsHooks implements
	SearchIndexFieldsHook,
	CirrusSearchAddQueryFeaturesHook,
	CirrusSearchAnalysisConfigHook,
	CirrusSearchSimilarityConfigHook
{
	public const FIELD_NAME = 'weighted_tags';
	public const FIELD_SIMILARITY = 'weighted_tags_similarity';
	public const FIELD_INDEX_ANALYZER = 'weighted_tags';
	public const FIELD_SEARCH_ANALYZER = 'keyword';
	private SearchConfig $config;

	public static function create( ConfigFactory $configFactory ): WeightedTagsHooks {
		/** @var SearchConfig $searchConfig */
		$searchConfig = $configFactory->makeConfig( 'CirrusSearch' );
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $searchConfig is actually a SearchConfig */
		return new self( $searchConfig );
	}

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * Visible for testing
	 * @return SearchConfig
	 */
	public function getConfig(): SearchConfig {
		return $this->config;
	}

	/**
	 * @inheritDoc
	 */
	public function onCirrusSearchSimilarityConfig( array &$similarity ): void {
		if ( !$this->canBuild() ) {
			return;
		}
		$maxScore = $this->maxScore();
		$similarity[self::FIELD_SIMILARITY] = [
			'type' => 'scripted',
			// no weight=>' script we do not want doc independent weighing
			'script' => [
				// apply boost close to docFreq to force int->float conversion
				'source' => "return (doc.freq*query.boost)/$maxScore;",
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function onSearchIndexFields( &$fields, $engine ) {
		if ( !( $engine instanceof CirrusSearch ) ) {
			return;
		}
		if ( !$this->canBuild() ) {
			return;
		}

		$fields[self::FIELD_NAME] = new WeightedTags(
			self::FIELD_NAME,
			self::FIELD_NAME,
			self::FIELD_INDEX_ANALYZER,
			self::FIELD_SEARCH_ANALYZER,
			self::FIELD_SIMILARITY
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onCirrusSearchAnalysisConfig( array &$analysisConfig, AnalysisConfigBuilder $analysisConfigBuilder ): void {
		if ( !$this->canBuild() ) {
			return;
		}
		$maxScore = $this->maxScore();
		$analysisConfig['analyzer'][self::FIELD_INDEX_ANALYZER] = [
			'type' => 'custom',
			'tokenizer' => 'keyword',
			'filter' => [
				'weighted_tags_term_freq',
			],
		];
		$analysisConfig['filter']['weighted_tags_term_freq'] = [
			'type' => 'term_freq',
			// must be a char that never appears in the topic names/ids
			'split_char' => '|',
			// max score (clamped), we assume that orig_score * 1000
			'max_tf' => $maxScore,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function onCirrusSearchAddQueryFeatures( SearchConfig $config, array &$extraFeatures ): void {
		if ( $this->canUse() ) {
			// articletopic keyword, matches by ORES  scores
			$extraFeatures[] = new ArticlePredictionKeyword();
			// article recommendations filter
			$extraFeatures[] = new HasRecommendationFeature( $this->maxScore() );
		}
	}

	/**
	 * Check whether weighted_tags data should be processed.
	 * @return bool
	 */
	private function canBuild(): bool {
		return (bool)( $this->config->get( 'CirrusSearchWeightedTags' )['build'] ?? false );
	}

	/**
	 * Check whether weighted_tags data is available for searching.
	 * @return bool
	 */
	private function canUse(): bool {
		return (bool)( $this->config->get( 'CirrusSearchWeightedTags' )['use'] ?? false );
	}

	private function maxScore(): int {
		return (int)( $this->config->get( 'CirrusSearchWeightedTags' )['max_score'] ?? 1000 );
	}
}
