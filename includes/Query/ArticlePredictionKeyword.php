<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\WeightedTagsHooks;
use CirrusSearch\WarningCollector;
use Elastica\Query\DisMax;
use Elastica\Query\Terms;
use MediaWiki\Message\Message;
use Wikimedia\Message\ListType;

/**
 * Finds pages based on how well they match a given keyword
 * (e.g.articletopic:term, articlecountry:term), based on scores provided by
 * (Wikimedia-specific) ML models.
 * @see WeightedTagsHooks
 * @see https://www.mediawiki.org/wiki/Help:CirrusSearch#Articletopic
 */
class ArticlePredictionKeyword extends SimpleKeywordFeature {
	public const ARTICLE_TOPIC_TAG_PREFIX = 'classification.prediction.articletopic';
	public const DRAFT_TOPIC_TAG_PREFIX = 'classification.prediction.drafttopic';
	public const ARTICLE_COUNTRY_TAG_PREFIX = 'classification.prediction.articlecountry';

	private const PREFIX_PER_KEYWORD = [
		'articletopic' => self::ARTICLE_TOPIC_TAG_PREFIX,
		'drafttopic' => self::DRAFT_TOPIC_TAG_PREFIX,
		'articlecountry' => self::ARTICLE_COUNTRY_TAG_PREFIX,
	];

	/**
	 * @var array<string, string|array<string>>
	 */
	private const TERMS_PER_KEYWORD = [
		'articletopic' => ArticleTopicFeature::TERMS_TO_LABELS,
		'drafttopic' => ArticleTopicFeature::TERMS_TO_LABELS,
		// Suppresses a warning when ArticleCountryFeature::AREA_CODES_TO_COUNTRY_CODES
		// is empty. Using + operator for compile-time array union since array_merge()
		// can't be used in constant definitions
		// @phan-suppress-next-line PhanUselessBinaryAddRight
		'articlecountry' => ArticleCountryFeature::COUNTRY_CODES_TO_LABELS +
			ArticleCountryFeature::AREA_CODES_TO_COUNTRY_CODES,
	];

	private const WARN_MESSAGE_PER_KEYWORD = "cirrussearch-articleprediction-invalid-keyword";

	/**
	 * @inheritDoc
	 * @phan-return array{keywords:array<array{terms:string[], boost:float|null}>,tag_prefix:string}
	 */
	public function parseValue(
		$key, $value, $quotedValue, $valueDelimiter, $suffix, WarningCollector $warningCollector
	) {
		$allowedTerms = self::TERMS_PER_KEYWORD[$key];
		$keywords = explode( '|', mb_strtolower( $value ) );
		$keywords = array_map( fn ( string $k ): array => $this->parseBoost( $k, $warningCollector ), $keywords );
		$invalidKeywords = array_diff(
			array_map( static fn ( array $k ): string => $k['term'], $keywords ),
			array_keys( $allowedTerms ) );

		$validKeywords = array_filter(
			$keywords,
			static fn ( array $k ): bool => array_key_exists( $k['term'], $allowedTerms )
		);

		$validKeywords = array_map(
			static function ( array $k ) use ( $allowedTerms ): array {
				$terms = $allowedTerms[$k['term']];
				if ( is_string( $terms ) ) {
					$terms = [ $terms ];
				}
				return [
					'terms' => $terms,
					'boost' => $k['boost']
				];
			},
			$validKeywords
		);

		if ( $invalidKeywords ) {
			$warningCollector->addWarning( self::WARN_MESSAGE_PER_KEYWORD,
				Message::listParam( $invalidKeywords, ListType::COMMA ), count( $invalidKeywords ), $key );
		}
		return [ 'keywords' => $validKeywords, 'tag_prefix' => self::PREFIX_PER_KEYWORD[$key] ];
	}

	/** @inheritDoc */
	protected function getKeywords() {
		return array_keys( self::PREFIX_PER_KEYWORD );
	}

	/** @inheritDoc */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$parsed = $this->parseValue( $key, $value, $quotedValue, '', '', $context );
		$keywords = $parsed['keywords'];
		if ( $keywords === [] ) {
			$context->setResultsPossible( false );
			return [ null, true ];
		}

		$query = new DisMax();
		foreach ( $keywords as $keyword ) {
			$terms = array_map( static fn ( string $k ): string => $parsed['tag_prefix'] . '/' . $k, $keyword['terms'] );
			$keywordQuery = new Terms( WeightedTagsHooks::FIELD_NAME, $terms );
			if ( $keyword['boost'] !== null ) {
				$keywordQuery->setBoost( $keyword['boost'] );
			}
			$query->addQuery( $keywordQuery );
		}

		if ( !$negated ) {
			$context->addNonTextQuery( $query );
			return [ null, false ];
		} else {
			return [ $query, false ];
		}
	}

}
