<?php

namespace CirrusSearch\Search;

use CirrusSearch\Searcher;
use MediaWiki\Logger\LoggerFactory;

/**
 * Lightweight classes to describe specific result types we can return.
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
interface ResultsType {
	/**
	 * Get the source filtering to be used loading the result.
	 *
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	function getSourceFiltering();

	/**
	 * Get the fields to load.  Most of the time we'll use source filtering instead but
	 * some fields aren't part of the source.
	 *
	 * @return array corresponding to Elasticsearch fields syntax
	 */
	function getStoredFields();

	/**
	 * Get the highlighting configuration.
	 *
	 * @param array $extraHighlightFields configuration for how to highlight regex matches.
	 *  Empty if regex should be ignored.
	 * @return array|null highlighting configuration for elasticsearch
	 */
	function getHighlightingConfiguration( array $extraHighlightFields );

	/**
	 * @param SearchContext $context
	 * @param \Elastica\ResultSet $result
	 * @return mixed Set of search results, the types of which vary by implementation.
	 */
	function transformElasticsearchResult( SearchContext $context, \Elastica\ResultSet $result );

	/**
	 * @return mixed Empty set of search results
	 */
	function createEmptyResult();
}

abstract class BaseResultsType implements ResultsType {

	/**
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering() {
		return [ 'namespace', 'title', 'namespace_text', 'wiki' ];
	}
}

/**
 * Returns titles and makes no effort to figure out how the titles matched.
 */
class TitleResultsType extends BaseResultsType {
	/**
	 * @return array corresponding to Elasticsearch fields syntax
	 */
	public function getStoredFields() {
		return [];
	}

	/**
	 * @param array $extraHighlightFields
	 * @return array|null
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields ) {
		return null;
	}

	/**
	 * @param SearchContext $context
	 * @param \Elastica\ResultSet $resultSet
	 * @return array
	 */
	public function transformElasticsearchResult( SearchContext $context, \Elastica\ResultSet $resultSet ) {
		$results = [];
		foreach ( $resultSet->getResults() as $r ) {
			$results[] = TitleHelper::makeTitle( $r );
		}
		return $results;
	}

	/**
	 * @return array
	 */
	public function createEmptyResult() {
		return [];
	}
}

/**
 * Returns titles categorized based on how they matched - redirect or name.
 */
class FancyTitleResultsType extends TitleResultsType {
	/** @var string */
	private $matchedAnalyzer;

	/**
	 * Build result type.   The matchedAnalyzer is required to detect if the match
	 * was from the title or a redirect (and is kind of a leaky abstraction.)
	 *
	 * @param string $matchedAnalyzer the analyzer used to match the title
	 */
	public function __construct( $matchedAnalyzer ) {
		$this->matchedAnalyzer = $matchedAnalyzer;
	}

	/**
	 * @param array $extraHighlightFields
	 * @return array|null
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields ) {
		global $wgCirrusSearchUseExperimentalHighlighter;

		if ( $wgCirrusSearchUseExperimentalHighlighter ) {
			// This is much less esoteric then the plain highlighter based
			// invocation but does the same thing.  The magic is that the none
			// fragmenter still fragments on multi valued fields.
			$entireValue = [
				'type' => 'experimental',
				'fragmenter' => 'none',
				'number_of_fragments' => 1,
			];
			$manyValues = [
				'type' => 'experimental',
				'fragmenter' => 'none',
				'order' => 'score',
			];
		} else {
			// This is similar to the FullTextResults type but against the near_match and
			// with the plain highlighter.  Near match because that is how the field is
			// queried.  Plain highlighter because we don't want to add the FVH's space
			// overhead for storing extra stuff and we don't need it for combining fields.
			$entireValue = [
				'type' => 'plain',
				'number_of_fragments' => 0,
			];
			$manyValues = [
				'type' => 'plain',
				'fragment_size' => 10000,   // We want the whole value but more than this is crazy
				'order' => 'score',
			];
		}
		$manyValues[ 'number_of_fragments' ] = 30;
		return [
			'pre_tags' => [ Searcher::HIGHLIGHT_PRE ],
			'post_tags' => [ Searcher::HIGHLIGHT_POST ],
			'fields' => [
				"title.$this->matchedAnalyzer" => $entireValue,
				"title.{$this->matchedAnalyzer}_asciifolding" => $entireValue,
				"redirect.title.$this->matchedAnalyzer" => $manyValues,
				"redirect.title.{$this->matchedAnalyzer}_asciifolding" => $manyValues,
			],
		];
	}

	/**
	 * Convert the results to titles.
	 *
	 * @param SearchContext $context
	 * @param \Elastica\ResultSet $resultSet
	 * @return array[] Array of arrays, each with optional keys:
	 *   titleMatch => a title if the title matched
	 *   redirectMatches => an array of redirect matches, one per matched redirect
	 */
	public function transformElasticsearchResult( SearchContext $context, \Elastica\ResultSet $resultSet ) {
		$results = [];
		foreach ( $resultSet->getResults() as $r ) {
			$results[] = $this->transformOneElasticResult( $r );
		}
		return $results;
	}

	/**
	 * Finds best title or redirect
	 * @param array $match array returned by self::transformOneElasticResult
	 * @return \Title|false choose best
	 */
	public static function chooseBestTitleOrRedirect( array $match ) {
		if ( isset( $match['titleMatch'] ) ) {
			return $match['titleMatch'];
		} else {
			if ( isset( $match['redirectMatches'][0] ) ) {
				// TODO maybe dig around in the redirect matches and find the best one?
				return $match['redirectMatches'][0];
			}
		}

		return false;
	}

	/**
	 * @return array
	 */
	public function createEmptyResult() {
		return [];
	}

	/**
	 * Transform a result from elastic into an array of Titles.
	 *
	 * @param \Elastica\Result $r
	 * @return \Title[] with the following keys :
	 *   titleMatch => a title if the title matched
	 *   redirectMatches => an array of redirect matches, one per matched redirect
	 */
	public function transformOneElasticResult( \Elastica\Result $r ) {
		$title = TitleHelper::makeTitle( $r );
		$highlights = $r->getHighlights();
		$resultForTitle = [];

		// Now we have to use the highlights to figure out whether it was the title or the redirect
		// that matched.  It is kind of a shame we can't really give the highlighting to the client
		// though.
		if ( isset( $highlights["title.$this->matchedAnalyzer"] ) ) {
			$resultForTitle['titleMatch'] = $title;
		} elseif ( isset( $highlights["title.{$this->matchedAnalyzer}_asciifolding"] ) ) {
			$resultForTitle['titleMatch'] = $title;
		}
		$redirectHighlights = [];

		if ( isset( $highlights["redirect.title.$this->matchedAnalyzer"] ) ) {
			$redirectHighlights = $highlights["redirect.title.$this->matchedAnalyzer"];
		}
		if ( isset( $highlights["redirect.title.{$this->matchedAnalyzer}_asciifolding"] ) ) {
			$redirectHighlights =
				array_merge( $redirectHighlights,
					$highlights["redirect.title.{$this->matchedAnalyzer}_asciifolding"] );
		}
		if ( count( $redirectHighlights ) !== 0 ) {
			foreach ( $redirectHighlights as $redirectTitle ) {
				// The match was against a redirect so we should replace the $title with one that
				// represents the redirect.
				// The first step is to strip the actual highlighting from the title.
				$redirectTitle = str_replace( [ Searcher::HIGHLIGHT_PRE, Searcher::HIGHLIGHT_POST ],
					'', $redirectTitle );

				// Instead of getting the redirect's real namespace we're going to just use the namespace
				// of the title.  This is not great but OK given that we can't find cross namespace
				// redirects properly any way.
				// TODO: ask the highlighter to return the namespace for this kind of matches
				// this would perhaps help to partially fix T115756
				$redirectTitle =
					TitleHelper::makeRedirectTitle( $r, $redirectTitle, $r->namespace );
				$resultForTitle['redirectMatches'][] = $redirectTitle;
			}
		}
		if ( count( $resultForTitle ) === 0 ) {
			// We're not really sure where the match came from so lets just pretend it was the title.
			LoggerFactory::getInstance( 'CirrusSearch' )
				->warning( "Title search result type hit a match but we can't " .
					"figure out what caused the match: {namespace}:{title}",
					[ 'namespace' => $r->namespace, 'title' => $r->title ] );
			$resultForTitle['titleMatch'] = $title;
		}

		return $resultForTitle;
	}
}

/**
 * Result type for a full text search.
 */
class FullTextResultsType extends BaseResultsType {
	const HIGHLIGHT_NONE = 0;
	const HIGHLIGHT_TITLE = 1;
	const HIGHLIGHT_ALT_TITLE = 2;
	const HIGHLIGHT_SNIPPET = 4;
	/**
	 * Should we highlight the file text?  Only used if HIGHLIGHT_SNIPPET is set.
	 */
	const HIGHLIGHT_FILE_TEXT = 8;
	const HIGHLIGHT_WITH_DEFAULT_SIMILARITY = 16;
	/**
	 * Should the alt title fields (heading and redirect) use postings or be reanalyzed?
	 */
	const HIGHLIGHT_ALT_TITLES_WITH_POSTINGS = 32;
	const HIGHLIGHT_ALL = 63;

	/**
	 * @var int Bitmask, see HIGHLIGHT_* consts
	 */
	private $highlightingConfig;

	/**
	 * @param int $highlightingConfig Bitmask, see HIGHLIGHT_* consts
	 */
	public function __construct( $highlightingConfig ) {
		$this->highlightingConfig = $highlightingConfig;
	}

	/**
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering() {
		return array_merge(
			parent::getSourceFiltering(),
			[ 'redirect.*', 'timestamp', 'text_bytes' ]
		);
	}

	/**
	 * @return array
	 */
	public function getStoredFields() {
		return [ "text.word_count" ]; // word_count is only a stored field and isn't part of the source.
	}

	/**
	 * Setup highlighting.
	 * Don't fragment title because it is small.
	 * Get just one fragment from the text because that is all we will display.
	 * Get one fragment from redirect title and heading each or else they
	 * won't be sorted by score.
	 *
	 * @param array $extraHighlightFields
	 * @return array|null of highlighting configuration
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields ) {
		global $wgCirrusSearchUseExperimentalHighlighter,
			$wgCirrusSearchFragmentSize;

		if ( $wgCirrusSearchUseExperimentalHighlighter ) {
			$entireValue = [
				'type' => 'experimental',
				'fragmenter' => 'none',
				'number_of_fragments' => 1,
			];
			$redirectAndHeading = [
				'type' => 'experimental',
				'fragmenter' => 'none',
				'order' => 'score',
				'number_of_fragments' => 1,
				'options' => [
					'skip_if_last_matched' => true,
				]
			];
			$remainingText = [
				'type' => 'experimental',
				'number_of_fragments' => 1,
				'fragmenter' => 'scan',
				'fragment_size' => $wgCirrusSearchFragmentSize,
				'options' => [
					'top_scoring' => true,
					'boost_before' => [
						// Note these values are super arbitrary right now.
						'20' => 2,
						'50' => 1.8,
						'200' => 1.5,
						'1000' => 1.2,
					],
					// We should set a limit on the number of fragments we try because if we
					// don't then we'll hit really crazy documents, say 10MB of "d d".  This'll
					// keep us from scanning more then the first couple thousand of them.
					// Setting this too low (like 50) can bury good snippets if the search
					// contains common words.
					'max_fragments_scored' => 5000,
					'skip_if_last_matched' => true,
				],
			];
			if ( !( $this->highlightingConfig & self::HIGHLIGHT_WITH_DEFAULT_SIMILARITY ) ) {
				$entireValue[ 'options' ][ 'default_similarity' ] = false;
				$redirectAndHeading[ 'options' ][ 'default_similarity' ] = false;
				$remainingText[ 'options' ][ 'default_similarity' ] = false;
			}
			if ( !( $this->highlightingConfig & self::HIGHLIGHT_ALT_TITLES_WITH_POSTINGS ) ) {
				$redirectAndHeading[ 'options' ][ 'hit_source' ] = 'analyze';
			}
		} else {
			$entireValue = [
				'number_of_fragments' => 0,
				'type' => 'fvh',
				'order' => 'score',
			];
			$redirectAndHeading = [
				'number_of_fragments' => 1, // Just one of the values in the list
				'fragment_size' => 10000,   // We want the whole value but more than this is crazy
				'type' => 'fvh',
				'order' => 'score',
			];
			$remainingText = [
				'number_of_fragments' => 1, // Just one fragment
				'fragment_size' => $wgCirrusSearchFragmentSize,
				'type' => 'fvh',
				'order' => 'score',
			];
		}
		// If there isn't a match just return a match sized chunk from the beginning of the page.
		$text = $remainingText;

		$config = [
			'pre_tags' => [ Searcher::HIGHLIGHT_PRE_MARKER ],
			'post_tags' => [ Searcher::HIGHLIGHT_POST_MARKER ],
			'fields' => [],
		];

		unset( $text[ 'options' ][ 'skip_if_last_matched' ] );
		if ( count( $extraHighlightFields ) ) {
			$this->configureHighlightingForRegex( $config, $extraHighlightFields, $text );
			return $config;
		}

		$text[ 'no_match_size' ] = $text[ 'fragment_size' ];

		$experimental = [];
		if ( $this->highlightingConfig & self::HIGHLIGHT_TITLE ) {
			$config[ 'fields' ][ 'title' ] = $entireValue;
		}
		if ( $this->highlightingConfig & self::HIGHLIGHT_ALT_TITLE ) {
			$config[ 'fields' ][ 'redirect.title' ] = $redirectAndHeading;
			$experimental[ 'fields' ][ 'redirect.title' ][ 'options' ][ 'skip_if_last_matched' ] = true;
			$config[ 'fields' ][ 'category' ] = $redirectAndHeading;
			$experimental[ 'fields' ][ 'category' ][ 'options' ][ 'skip_if_last_matched' ] = true;
			$config[ 'fields' ][ 'heading' ] = $redirectAndHeading;
			$experimental[ 'fields' ][ 'heading' ][ 'options' ][ 'skip_if_last_matched' ] = true;
		}
		if ( $this->highlightingConfig & self::HIGHLIGHT_SNIPPET ) {
			$config[ 'fields' ][ 'text' ] = $text;
			$config[ 'fields' ][ 'auxiliary_text' ] = $remainingText;
			$experimental[ 'fields' ][ 'auxiliary_text' ][ 'options' ][ 'skip_if_last_matched' ] = true;
			if ( $this->highlightingConfig & self::HIGHLIGHT_FILE_TEXT ) {
				$config[ 'fields' ][ 'file_text' ] = $remainingText;
				$experimental[ 'fields' ][ 'file_text' ][ 'options' ][ 'skip_if_last_matched' ] = true;
			}
		}
		$config[ 'fields' ] = $this->addMatchedFields( $config[ 'fields' ] );

		if ( $wgCirrusSearchUseExperimentalHighlighter ) {
			$config = $this->arrayMergeRecursive( $config, $experimental );
		}

		return $config;
	}

	/**
	 * Behaves like array_merge with recursive descent. Unlike array_merge_recursive,
	 * but just like array_merge, this does not convert non-arrays into arrays.
	 *
	 * @param array $source
	 * @param array $overrides
	 * @return array
	 */
	private function arrayMergeRecursive( array $source, array $overrides ) {
		foreach ( $source as $k => $v ) {
			if ( isset( $overrides[$k] ) ) {
				if ( is_array( $overrides[$k] ) ) {
					$source[$k] = $this->arrayMergeRecursive( $v, $overrides[$k] );
				} else {
					$source[$k] = $overrides[$k];
				}
			}
		}
		return $source;
	}

	/**
	 * @param SearchContext $context
	 * @param \Elastica\ResultSet $result
	 * @return ResultSet
	 */
	public function transformElasticsearchResult( SearchContext $context, \Elastica\ResultSet $result ) {
		return new ResultSet(
			$context->getSuggestPrefixes(),
			$context->getSuggestSuffixes(),
			$result,
			$context->isSpecialKeywordUsed()
		);
	}

	/**
	 * @return EmptyResultSet
	 */
	public function createEmptyResult() {
		return new EmptyResultSet();
	}

	/**
	 * @param array[] $fields
	 * @return array[]
	 */
	private function addMatchedFields( $fields ) {
		foreach ( array_keys( $fields ) as $name ) {
			$fields[$name]['matched_fields'] = [ $name, "$name.plain" ];
		}
		return $fields;
	}

	/**
	 * @param array &$config
	 * @param array $extraHighlightFields
	 * @param array $options
	 */
	private function configureHighlightingForRegex( array &$config, array $extraHighlightFields, array $options ) {
		global $wgCirrusSearchRegexMaxDeterminizedStates,
			$wgCirrusSearchUseExperimentalHighlighter;

		$includes_text = false;
		foreach ( $extraHighlightFields as $field => $parts ) {
			$isTextField = $field == 'text' || $field == 'source_text';
			$fieldOptions = $options;
			if ( $isTextField ) {
				$includes_text = true;
				$fieldOptions['no_match_size'] = $fieldOptions['fragment_size'];
			}
			$patterns = [];
			$locale = null;
			$caseInsensitive = false;
			foreach ( $parts as $part ) {
				if ( isset( $part[ 'pattern' ] ) ) {
					$patterns[] = $part[ 'pattern' ];
					$locale = $part[ 'locale' ];
					$caseInsensitive |= $part[ 'insensitive' ];
				}
			}
			if ( count( $patterns ) && $wgCirrusSearchUseExperimentalHighlighter ) {
				// highlight for regex queries is only supported by the experimental
				// highlighter.
				$config['fields']["$field.plain"] = $fieldOptions;
				$fieldOptions = [
					'regex' => $patterns,
					'locale' => $locale,
					'regex_flavor' => 'lucene',
					'skip_query' => true,
					'regex_case_insensitive' => (bool)$caseInsensitive,
					'max_determinized_states' => $wgCirrusSearchRegexMaxDeterminizedStates,
				];
				if ( isset( $config['fields']["$field.plain"]['options'] ) ) {
					$config[ 'fields' ][ "$field.plain" ][ 'options' ] = array_merge(
						$config[ 'fields' ][ "$field.plain" ][ 'options' ],
						$fieldOptions
					);
				} else {
					$config[ 'fields' ][ "$field.plain" ][ 'options' ] = $fieldOptions;
				}
			} else {
				foreach ( $extraHighlightFields as $field => $parts ) {
					$queryStrings = [];
					foreach ( $parts as $part ) {
						if ( isset( $part[ 'query' ] ) ) {
							$queryStrings[] = $part[ 'query' ];
						}
					}
					if ( count( $queryStrings ) ) {
						$config['fields']["$field.plain"] = $fieldOptions;
						$bool = new \Elastica\Query\BoolQuery();
						foreach ( $queryStrings as $queryString ) {
							$bool->addShould( $queryString );
						}
						$config[ 'fields' ][ "$field.plain" ][ 'highlight_query' ] = $bool->toArray();
					}
				}
			}
		}
		if ( !$includes_text ) {
			// Return the beginning of text as the content snippet
			$config['fields']['text'] = $options;
			$config['fields']['text']['no_match_size'] = $options[ 'fragment_size' ];
			unset( $config['fields']['text']['options']['skip_if_last_matched'] );
		}
	}
}

/**
 * Returns page ids. Less CPU load on Elasticsearch since all we're returning
 * is an id.
 */
class IdResultsType implements ResultsType {
	/**
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering() {
		return false;
	}

	public function getStoredFields() {
		return [];
	}

	public function getHighlightingConfiguration( array $extraHighlightFields ) {
		return null;
	}

	/**
	 * @param SearchContext $context
	 * @param \Elastica\ResultSet $resultSet
	 * @return string[]
	 */
	public function transformElasticsearchResult( SearchContext $context, \Elastica\ResultSet $resultSet ) {
		$results = [];
		foreach ( $resultSet->getResults() as $r ) {
			$results[] = $r->getId();
		}
		return $results;
	}

	/**
	 * @return string[]
	 */
	public function createEmptyResult() {
		return [];
	}
}

class SingleAggResultsType implements ResultsType {
	/** @var string Name of aggregation */
	private $name;

	/** @param string $name Name of aggregation to return */
	public function __construct( $name ) {
		$this->name = $name;
	}

	/**
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering() {
		return false;
	}

	public function getStoredFields() {
		return [];
	}

	public function getHighlightingConfiguration( array $extraHighlightFields ) {
		return null;
	}

	/**
	 * @param SearchContext $context
	 * @param \Elastica\ResultSet $resultSet
	 * @return mixed|null Type depends on the aggregation performed. For
	 *  a sum this will return an integer.
	 */
	public function transformElasticsearchResult( SearchContext $context, \Elastica\ResultSet $resultSet ) {
		$aggs = $resultSet->getAggregations();
		if ( isset( $aggs[$this->name] ) ) {
			return $aggs[$this->name]['value'];
		}
		return $this->createEmptyResult();
	}

	/**
	 * @return null
	 */
	public function createEmptyResult() {
		return null;
	}
}
