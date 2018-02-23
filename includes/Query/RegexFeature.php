<?php

namespace CirrusSearch\Query;

use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\SearchConfig;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use Elastica\Query\AbstractQuery;

/**
 * Implements an in{foo}: keyword supporting regular expression matching
 * against properly indexed fields. Works best when combined with the
 * wikimedia-extra plugin for elasticsearch, but can also fallback to a groovy
 * based implementation. Can be really expensive, but mostly ok if you have the
 * extra plugin enabled.
 *
 * Examples:
 *   insource:/abc?/
 */
class RegexFeature implements KeywordFeature {
	/**
	 * @var string used as keyword such as 'source' for insource:
	 */
	private $name;

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
	 * @param SearchConfig $config
	 * @param string $name
	 * @param string[]|string|null $fields
	 */
	public function __construct( SearchConfig $config, $name, $fields = null ) {
		$this->enabled = $config->get( 'CirrusSearchEnableRegex' );
		$this->languageCode = $config->get( 'LanguageCode' );
		$this->regexPlugin = $config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'regex' );
		$this->maxDeterminizedStates = $config->get( 'CirrusSearchRegexMaxDeterminizedStates' );
		$this->name = $name;
		$fields = $fields == null ? $name : $fields;
		$this->fields = is_array( $fields ) ? $fields : [ $fields ];
	}

	/**
	 * @param SearchContext $context
	 * @param string $term
	 * @return string
	 */
	public function apply( SearchContext $context, $term ) {
		return QueryHelper::extractSpecialSyntaxFromTerm(
			$context,
			$term,
			'/(?<not>-)?in' . $this->name . ':\/(?<pattern>(?:[^\\\\\/]|\\\\.)+)\/(?<insensitive>i)? ?/',
			function ( $matches ) use ( $context ) {
				if ( !$this->enabled ) {
					$context->addWarning(
						'cirrussearch-feature-not-available',
						"in{$this->name} regex"
					);
					return '';
				}

				$context->addSyntaxUsed( 'regex' );
				$insensitive = !empty( $matches['insensitive'] );

				$filter = $this->regexPlugin && in_array( 'use', $this->regexPlugin )
					? $this->buildRegexWithPlugin( $matches['pattern'], $insensitive, $context )
					: $this->buildRegexWithGroovy( $matches['pattern'], $insensitive );

				if ( empty( $matches['not'] ) ) {
					$context->addFilter( $filter );
					foreach ( $this->fields as $field ) {
						$context->addHighlightField( $field, [
							'pattern' => $matches['pattern'],
							'locale' => $this->languageCode,
							'insensitive' => $insensitive,
						] );
					}
				} else {
					$context->addNotFilter( $filter );
				}
			}
		);
	}

	/**
	 * Builds a regular expression query using the wikimedia-extra plugin.
	 *
	 * @param string $pattern The regular expression to match
	 * @param bool $insensitive Should the match be case insensitive?
	 * @param SearchContext $context
	 * @return AbstractQuery Regular expression query
	 */
	private function buildRegexWithPlugin( $pattern, $insensitive, SearchContext $context ) {
		$filters = [];
		$timeout = $context->getConfig()->getElement( 'CirrusSearchSearchShardTimeout', 'regex' );
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

			if ( $timeout && in_array( 'use_extra_timeout', $this->regexPlugin ) ) {
				$filter->setTimeout( $timeout );
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
