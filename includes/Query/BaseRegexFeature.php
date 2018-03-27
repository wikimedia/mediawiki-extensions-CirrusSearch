<?php

namespace CirrusSearch\Query;

use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\SearchConfig;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use Elastica\Query\AbstractQuery;
use Wikimedia\Assert\Assert;

/**
 * Base class supporting regex searches. Works best when combined with the
 * wikimedia-extra plugin for elasticsearch, but can also fallback to a groovy
 * based implementation. Can be really expensive, but mostly ok if you have the
 * extra plugin enabled.
 *
 * Examples:
 *   insource:/abc?/
 */
abstract class BaseRegexFeature extends SimpleKeywordFeature {
	/**
	 * @var string[] Elasticsearch field(s) to search against
	 */
	private $fields;

	/**
	 * @var bool Is this feature enabled?
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
	 * against overloading the search cluster. Only works when using the
	 * extra plugin, groovy based execution is unbounded.
	 */
	private $maxDeterminizedStates;

	/**
	 * @var string $shardTimeout timeout for regex queries
	 * with the extra plugin
	 */
	private $shardTimeout;

	/**
	 * @param SearchConfig $config
	 * @param string[] $fields
	 */
	public function __construct( SearchConfig $config, array $fields ) {
		$this->enabled = $config->get( 'CirrusSearchEnableRegex' );
		$this->languageCode = $config->get( 'LanguageCode' );
		$this->regexPlugin = $config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'regex' );
		$this->maxDeterminizedStates = $config->get( 'CirrusSearchRegexMaxDeterminizedStates' );
		Assert::precondition( count( $fields ) > 0, 'must have at least one field' );
		$this->fields = $fields;
		$this->shardTimeout = $config->getElement( 'CirrusSearchSearchShardTimeout', 'regex' );
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
	 * @param SearchContext $context
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param string $negated
	 * @param string $delimiter
	 * @param string $suffix
	 * @return array
	 */
	public function doApplyExtended( SearchContext $context, $key, $value, $quotedValue, $negated, $delimiter, $suffix ) {
		if ( $delimiter === '/' ) {
			if ( !$this->enabled ) {
				$context->addWarning(
					'cirrussearch-feature-not-available',
					"$key regex"
				);
				return [ null, false ];
			}

			$pattern = trim( $quotedValue, '/' );
			$insensitive = $suffix === 'i';
			$filter = $this->buildRegexQuery( $pattern, $insensitive );
			if ( !$negated ) {
				$this->configureHighlighting( $pattern, $insensitive, $context );
			}
			return [ $filter, false ];
		} else {
			return $this->doApply( $context, $key, $value, $quotedValue, $negated );
		}
	}

	/**
	 * @param $pattern
	 * @param $insensitive
	 * @return AbstractQuery
	 */
	private function buildRegexQuery( $pattern, $insensitive ) {
		return $this->regexPlugin && in_array( 'use', $this->regexPlugin )
			? $this->buildRegexWithPlugin( $pattern, $insensitive )
			: $this->buildRegexWithGroovy( $pattern, $insensitive );
	}

	private function configureHighlighting( $pattern, $insensitive, SearchContext $context ) {
		foreach ( $this->fields as $field ) {
			$context->addHighlightField( $field, [
				'pattern' => $pattern,
				'locale' => $this->languageCode,
				'insensitive' => $insensitive,
			] );
		}
	}
	/**
	 * Builds a regular expression query using the wikimedia-extra plugin.
	 *
	 * @param string $pattern The regular expression to match
	 * @param bool $insensitive Should the match be case insensitive?
	 * @return AbstractQuery Regular expression query
	 */
	private function buildRegexWithPlugin( $pattern, $insensitive ) {
		$filters = [];
		// TODO: Update plugin to accept multiple values for the field property
		// so that at index time we can create a single trigram index with
		// copy_to instead of creating multiple queries.
		foreach ( $this->fields as $field ) {
			$filter = new SourceRegex( $pattern, $field, $field . '.trigram' );
			// set some defaults
			$filter->setMaxDeterminizedStates( $this->maxDeterminizedStates );
			if ( isset( $this->regexPlugin['max_ngrams_extracted'] ) ) {
				$filter->setMaxNgramsExtracted( $this->regexPlugin['max_ngrams_extracted'] );
			}
			if ( isset( $this->regexPlugin['max_ngram_clauses'] ) && is_numeric( $this->regexPlugin['max_ngram_clauses'] ) ) {
				$filter->setMaxNgramClauses( (int)$this->regexPlugin['max_ngram_clauses'] );
			}
			$filter->setCaseSensitive( !$insensitive );
			$filter->setLocale( $this->languageCode );

			if ( $this->shardTimeout && in_array( 'use_extra_timeout', $this->regexPlugin ) ) {
				$filter->setTimeout( $this->shardTimeout );
			}

			$filters[] = $filter;
		}

		return Filters::booleanOr( $filters );
	}

	/**
	 * Builds a regular expression query using groovy. It's significantly less
	 * good than the wikimedia-extra plugin, but it's something.
	 *
	 * @param string $pattern The regular expression to match
	 * @param bool $insensitive Should the match be case insensitive?
	 * @return AbstractQuery Regular expression query
	 */
	private function buildRegexWithGroovy( $pattern, $insensitive ) {
		$filters = [];
		foreach ( $this->fields as $field ) {
			$script = <<<GROOVY
import org.apache.lucene.util.automaton.*;
sourceText = _source.get("{$field}");
if (sourceText == null) {
    false;
} else {
    if (automaton == null) {
        if (insensitive) {
            locale = new Locale(language);
            pattern = pattern.toLowerCase(locale);
        }
        regexp = new RegExp(pattern, RegExp.ALL ^ RegExp.AUTOMATON);
        automaton = new CharacterRunAutomaton(regexp.toAutomaton());
    }
    if (insensitive) {
        sourceText = sourceText.toLowerCase(locale);
    }
    automaton.run(sourceText);
}

GROOVY;

			$filters[] = new \Elastica\Query\Script( new \Elastica\Script\Script(
				$script,
				[
					'pattern' => '.*(' . $pattern . ').*',
					'insensitive' => $insensitive,
					'language' => $this->languageCode,
					// The null here creates a slot in which the script will shove
					// an automaton while executing.
					'automaton' => null,
					'locale' => null,
				],
				'groovy'
			) );
		}

		return Filters::booleanOr( $filters );
	}
}
