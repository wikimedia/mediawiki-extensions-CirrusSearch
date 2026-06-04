<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\Fetch\FetchPhaseConfigBuilder;
use CirrusSearch\Search\Fetch\HighlightedField;
use CirrusSearch\Search\Fetch\HighlightFieldGenerator;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use MediaWiki\MainConfigNames;
use Wikimedia\Assert\Assert;

/**
 * Base class supporting regex searches. Requires the wikimedia-extra plugin for
 * elasticsearch. Can be really expensive, but mostly ok with the extra plugin
 * enabled.
 *
 * Examples:
 *   insource:/abc?/
 *
 * @see SourceRegex
 */
abstract class BaseRegexFeature extends SimpleKeywordFeature implements FilterQueryFeature, HighlightingFeature {
	/**
	 * @var string[] Elasticsearch field(s) to search against
	 */
	private $fields;

	/**
	 * @var bool Is this feature enabled? Requires both CirrusSearchEnableRegex
	 *  and the wikimedia-extra plugin's regex support to be enabled.
	 */
	private $enabled;

	/**
	 * @var string Locale used for case conversions. It's important that this
	 *  matches the locale used for lowercasing in the ngram index.
	 */
	private $languageCode;

	/**
	 * @var string[] Configuration flags for the regex plugin
	 */
	private $regexPlugin;

	/**
	 * @var int The maximum number of automaton states that Lucene's regex
	 * compilation can expand to (even temporarily). Provides protection
	 * against overloading the search cluster.
	 */
	private $maxDeterminizedStates;

	/**
	 * @var string timeout for regex queries
	 * with the extra plugin
	 */
	private $shardTimeout;

	/**
	 * @param SearchConfig $config
	 * @param string[] $fields
	 */
	public function __construct( SearchConfig $config, array $fields ) {
		$this->languageCode = $config->get( MainConfigNames::LanguageCode );
		$this->regexPlugin = $config->getElement( CirrusConfigNames::WikimediaExtraPlugin, 'regex' );
		// Regex is only usable when the wikimedia-extra plugin is available to serve it.
		$this->enabled = $config->get( CirrusConfigNames::EnableRegex )
			&& $this->regexPlugin && in_array( 'use', $this->regexPlugin );
		$this->maxDeterminizedStates = $config->get( CirrusConfigNames::RegexMaxDeterminizedStates );
		Assert::precondition( $fields !== [], 'must have at least one field' );
		$this->fields = $fields;
		$this->shardTimeout = $config->getElement( CirrusConfigNames::SearchShardTimeout, 'regex' );
	}

	/**
	 * The field set to query/highlight for this request.
	 *
	 * In redirect scope the fields under the `redirect.` prefix are dropped,
	 * otherwise the full set is used. This has to be part of parsing, rather
	 * than having appropriate fields provided in the constructor, because at
	 * construction time we don't know if redirect scope is enabled.
	 *
	 * @param bool $isRedirectScope
	 * @return string[]
	 */
	private function effectiveFields( bool $isRedirectScope ): array {
		if ( !$isRedirectScope ) {
			return $this->fields;
		}
		return array_filter(
			$this->fields,
			static fn ( $field ) => Filters::allowFieldInRedirectScope( $field ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * @return string[][]
	 */
	public function getValueDelimiters() {
		return [
			[
				// simple search
				'delimiter' => '"'
			],
			[
				// regex searches
				'delimiter' => '/',
				// optional case insensitive suffix
				'suffixes' => 'i'
			]
		];
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param string $valueDelimiter
	 * @param string $suffix
	 * @param WarningCollector $warningCollector
	 * @return array|false|null
	 */
	public function parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix, WarningCollector $warningCollector ) {
		if ( $valueDelimiter === '/' ) {
			if ( !$this->enabled ) {
				$warningCollector->addWarning( 'cirrussearch-feature-not-available', "$key regex" );
			}

			$pattern = $this->trimFirstOccurrenceOfSlash( $quotedValue );

			if ( $pattern === '' ) {
				$warningCollector->addWarning( 'cirrussearch-regex-empty-expression', $key );
			}

			return [
				'type' => 'regex',
				'pattern' => $pattern,
				'insensitive' => $suffix === 'i',
			];
		}
		return parent::parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix, $warningCollector );
	}

	/**
	 * @param string $key
	 * @param string $valueDelimiter
	 * @return string
	 */
	public function getFeatureName( $key, $valueDelimiter ) {
		if ( $valueDelimiter === '/' ) {
			return 'regex';
		}
		return parent::getFeatureName( $key, $valueDelimiter );
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		if ( $node->getDelimiter() === '/' ) {
			return CrossSearchStrategy::hostWikiOnlyStrategy();
		} else {
			return CrossSearchStrategy::allWikisStrategy();
		}
	}

	/**
	 * @param SearchContext $context
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param bool $negated
	 * @param string $delimiter
	 * @param string $suffix
	 * @return array
	 */
	public function doApplyExtended( SearchContext $context, $key, $value, $quotedValue, $negated, $delimiter, $suffix ) {
		$parsedValue = $this->parseValue( $key, $value, $quotedValue, $delimiter, $suffix, $context );
		if ( $this->isRegexQuery( $parsedValue ) ) {
			if ( !$this->enabled ) {
				return [ null, false ];
			}
			'@phan-var array $parsedValue';
			$pattern = $parsedValue['pattern'];
			$insensitive = $parsedValue['insensitive'];

			if ( $pattern === '' ) {
				$context->setResultsPossible( false );

				return [ null, false ];
			}

			$fields = $this->effectiveFields( $context->isRedirectScope() );
			$filter = $this->buildRegexQuery( $fields, $pattern, $insensitive );
			if ( !$negated ) {
				$this->configureHighlighting( $fields, $pattern, $insensitive, $context->getFetchPhaseBuilder() );
			}
			return [ $filter, false ];
		} else {
			return $this->doApply( $context, $key, $value, $quotedValue, $negated );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		$parsedValue = $node->getParsedValue();
		if ( $this->isRegexQuery( $parsedValue ) ) {
			if ( !$this->enabled ) {
				return null;
			}
			'@phan-var array $parsedValue';
			$pattern = $parsedValue['pattern'];
			$insensitive = $parsedValue['insensitive'];
			return $this->buildRegexQuery( $this->effectiveFields( $context->isRedirectScope() ), $pattern, $insensitive );
		} else {
			return $this->getNonRegexFilterQuery( $node, $context );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function buildHighlightFields( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		$parsedValue = $node->getParsedValue();
		if ( $this->isRegexQuery( $parsedValue ) ) {
			if ( !$this->enabled ) {
				return [];
			}
			'@phan-var array $parsedValue';
			$pattern = $parsedValue['pattern'];
			$insensitive = $parsedValue['insensitive'];
			return $this->doGetRegexHLFields( $context->getHighlightFieldGenerator(),
				$this->effectiveFields( $context->isRedirectScope() ), $pattern, $insensitive );
		}
		return $this->buildNonRegexHLFields( $node, $context );
	}

	/**
	 * Obtain the filter when the keyword is used in non regex mode.
	 * This method will be called on syntax like keyword:word or keyword:"phrase"
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	abstract protected function getNonRegexFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context );

	/**
	 * Determine the flavor of regex highlighting to apply.
	 * @return string one of: java, lucene, lucene_extended, lucene_anchored
	 */
	abstract protected function getRegexHLFlavor(): string;

	/**
	 * @param string[] $fields
	 * @param string $pattern
	 * @param bool $insensitive
	 * @param FetchPhaseConfigBuilder $fetchPhaseConfigBuilder
	 */
	private function configureHighlighting( array $fields, $pattern, $insensitive, FetchPhaseConfigBuilder $fetchPhaseConfigBuilder ) {
		foreach ( $this->doGetRegexHLFields( $fetchPhaseConfigBuilder, $fields, $pattern, $insensitive ) as $f ) {
			$fetchPhaseConfigBuilder->addHLField( $f );
		}
	}

	/**
	 * @param HighlightFieldGenerator $generator
	 * @param string[] $fields
	 * @param string $pattern
	 * @param bool $insensitive
	 * @return HighlightedField[]
	 */
	private function doGetRegexHLFields( HighlightFieldGenerator $generator, array $fields, $pattern, $insensitive ) {
		$hlFields = [];
		if ( !$generator->supportsRegexFields() ) {
			return $hlFields;
		}
		$regexFlavor = $this->getRegexHLFlavor();
		foreach ( $fields as $field => $hlTarget ) {
			$hlFields[] = $generator->newRegexField( "$field.plain", $hlTarget,
				$pattern, $insensitive, HighlightedField::COSTLY_EXPERT_SYNTAX_PRIORITY,
				$regexFlavor );
		}
		return $hlFields;
	}

	/**
	 * Builds a regular expression query using the wikimedia-extra plugin.
	 *
	 * @param string[] $fields
	 * @param string $pattern The regular expression to match
	 * @param bool $insensitive Should the match be case insensitive?
	 * @return AbstractQuery Regular expression query
	 */
	private function buildRegexQuery( array $fields, $pattern, $insensitive ) {
		$filters = [];
		// TODO: Update plugin to accept multiple values for the field property
		// so that at index time we can create a single trigram index with
		// copy_to instead of creating multiple queries.
		foreach ( $fields as $field => $hlTarget ) {
			$filter = new SourceRegex( $pattern, $field, $field . '.trigram' );
			// set some defaults
			$filter->setMaxDeterminizedStates( $this->maxDeterminizedStates );
			if ( isset( $this->regexPlugin['max_ngrams_extracted'] ) && is_numeric( $this->regexPlugin['max_ngrams_extracted'] ) ) {
				$filter->setMaxNgramsExtracted( (int)$this->regexPlugin['max_ngrams_extracted'] );
			}
			if ( isset( $this->regexPlugin['max_ngram_clauses'] ) && is_numeric( $this->regexPlugin['max_ngram_clauses'] ) ) {
				$filter->setMaxNgramClauses( (int)$this->regexPlugin['max_ngram_clauses'] );
			}
			$filter->setCaseSensitive( !$insensitive );
			$filter->setLocale( $this->languageCode );

			$filters[] = $filter;
		}

		return Filters::booleanOr( $filters );
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return HighlightedField[]
	 */
	abstract public function buildNonRegexHLFields( KeywordFeatureNode $node, QueryBuildingContext $context );

	/**
	 * @param array|null $parsedValue
	 * @return bool
	 */
	private function isRegexQuery( ?array $parsedValue = null ) {
		return is_array( $parsedValue ) && isset( $parsedValue['type'] ) &&
			   $parsedValue['type'] === 'regex';
	}

	/**
	 * @param string $quotedValue
	 * @return false|string
	 */
	private function trimFirstOccurrenceOfSlash( string $quotedValue ) {
		$pattern = $quotedValue;
		if ( str_starts_with( $pattern, '/' ) ) {
			$pattern = substr( $pattern, 1 );
		}
		if ( str_ends_with( $pattern, '/' ) ) {
			$pattern = substr( $pattern, 0, -1 );
		}

		return $pattern;
	}
}
