<?php

namespace CirrusSearch\Query;

use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\SearchConfig;
use CirrusSearch\Search\SearchContext;
use Elastica\Query\AbstractQuery;

/**
 * Implements an insource: keyword supporting regular expression matching
 * against wikitext source. Works best when combined with the wikimedia-extra
 * plugin for elasticsearch, but can also fallback to a groovy based
 * implementation. Can be really expensive, but mostly ok if you have the extra
 * plugin enabled.
 *
 * Examples:
 *   insource:/abc?/
 */
class RegexInSourceFeature implements KeywordFeature {
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
	 */
	public function __construct( SearchConfig $config ) {
		$this->enabled = $config->get( 'CirrusSearchEnableRegex' );
		$this->languageCode = $config->get( 'LanguageCode' );
		$this->regexPlugin = $config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'regex' );
		$this->maxDeterminizedStates = $config->get( 'CirrusSearchRegexMaxDeterminizedStates' );
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
            '/(?<not>-)?insource:\/(?<pattern>(?:[^\\\\\/]|\\\\.)+)\/(?<insensitive>i)? ?/',
			function ( $matches ) use ( $context ) {
				if ( !$this->enabled ) {
					return '';
				}

				$context->addSyntaxUsed( 'regex' );
				$context->setSearchType( 'regex' );
				$insensitive = !empty( $matches['insensitive'] );

				$filter = $this->regexPlugin && in_array( 'use', $this->regexPlugin )
					? $this->buildRegexWithPlugin( $matches['pattern'], $insensitive )
					: $this->buildRegexWithGroovy( $matches['pattern'], $insensitive );

				if ( empty( $matches['not'] ) ) {
					$context->addFilter( $filter );
					$context->addHighlightSource( [
						'pattern' => $matches['pattern'],
						'locale' => $this->languageCode,
						'insensitive' => $insensitive,
					] );
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
	 * @return AbstractQuery Regular expression query
	 */
	private function buildRegexWithPlugin( $pattern, $insensitive ) {
		$filter = new SourceRegex( $pattern, 'source_text', 'source_text.trigram' );
		// set some defaults
		$this->regexPlugin += [
			'max_inspect' => 10000,
		];
		$filter->setMaxInspect( isset( $this->regexPlugin['max_inspect'] )
			? $this->regexPlugin['max_inspect']
			: 10000
		);
		$filter->setMaxDeterminizedStates( $this->maxDeterminizedStates );
		if ( isset( $this->regexPlugin['max_ngrams_extracted'] ) ) {
			$filter->setMaxNgramsExtracted( $this->regexPlugin['max_ngrams_extracted'] );
		}
		if ( isset( $this->regexPlugin['max_ngram_clauses'] ) && is_numeric( $this->regexPlugin['max_ngram_clauses'] ) ) {
			$filter->setMaxNgramClauses( (int) $this->regexPlugin['max_ngram_clauses'] );
		}
		$filter->setCaseSensitive( !$insensitive );
		$filter->setLocale( $this->languageCode );

		return $filter;
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
		$script = <<<GROOVY
import org.apache.lucene.util.automaton.*;
sourceText = _source.get("source_text");
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

		return new \Elastica\Query\Script( new \Elastica\Script\Script(
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
}
