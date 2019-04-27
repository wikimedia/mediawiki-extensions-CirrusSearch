<?php

namespace CirrusSearch\Search;

use CirrusSearch\Searcher;
use Elastica\ResultSet as ElasticaResultSet;

/**
 * Result type for a full text search.
 */
class FullTextResultsType extends BaseResultsType {
	/**
	 * @var bool
	 */
	private $searchContainedSyntax;

	/**
	 * @param bool $searchContainedSyntax
	 */
	public function __construct( $searchContainedSyntax = false ) {
		$this->searchContainedSyntax = $searchContainedSyntax;
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
		global $wgCirrusSearchUseExperimentalHighlighter, $wgCirrusSearchFragmentSize;

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
		$config[ 'fields' ][ 'title' ] = $entireValue;
		$config[ 'fields' ][ 'redirect.title' ] = $redirectAndHeading;
		$experimental[ 'fields' ][ 'redirect.title' ][ 'options' ][ 'skip_if_last_matched' ] = true;
		$config[ 'fields' ][ 'category' ] = $redirectAndHeading;
		$experimental[ 'fields' ][ 'category' ][ 'options' ][ 'skip_if_last_matched' ] = true;
		$config[ 'fields' ][ 'heading' ] = $redirectAndHeading;
		$experimental[ 'fields' ][ 'heading' ][ 'options' ][ 'skip_if_last_matched' ] = true;
		$config[ 'fields' ][ 'text' ] = $text;
		$config[ 'fields' ][ 'auxiliary_text' ] = $remainingText;
		$experimental[ 'fields' ][ 'auxiliary_text' ][ 'options' ][ 'skip_if_last_matched' ] = true;
		$config[ 'fields' ][ 'file_text' ] = $remainingText;
		$experimental[ 'fields' ][ 'file_text' ][ 'options' ][ 'skip_if_last_matched' ] = true;
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
	 * @param ElasticaResultSet $result
	 * @return ResultSet
	 */
	public function transformElasticsearchResult( ElasticaResultSet $result ) {
		return new ResultSet(
			$this->searchContainedSyntax,
			$result
		);
	}

	/**
	 * @return ResultSet
	 */
	public function createEmptyResult() {
		return ResultSet::emptyResultSet();
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
		global $wgCirrusSearchRegexMaxDeterminizedStates, $wgCirrusSearchUseExperimentalHighlighter;

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
				$queryStrings = [];
				foreach ( $parts as $part ) {
					if ( isset( $part['query'] ) ) {
						$queryStrings[] = $part['query'];
					}
				}
				if ( count( $queryStrings ) ) {
					$config['fields']["$field.plain"] = $fieldOptions;
					$bool = new \Elastica\Query\BoolQuery();
					foreach ( $queryStrings as $queryString ) {
						$bool->addShould( $queryString );
					}
					$config['fields']["$field.plain"]['highlight_query'] = $bool->toArray();
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
