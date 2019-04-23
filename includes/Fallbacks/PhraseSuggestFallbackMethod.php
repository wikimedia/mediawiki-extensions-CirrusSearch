<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\OtherIndexes;
use CirrusSearch\Parser\AST\Visitor\QueryFixer;
use CirrusSearch\Parser\BasicQueryClassifier;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Searcher;
use Wikimedia\Assert\Assert;

/**
 * Fallback method based on the elastic phrase suggester.
 */
class PhraseSuggestFallbackMethod implements FallbackMethod, ElasticSearchSuggestFallbackMethod {
	use FallbackMethodTrait;

	/**
	 * @var SearchQuery
	 */
	private $query;

	/**
	 * @var SearcherFactory
	 */
	private $searcherFactory;

	/**
	 * @var QueryFixer
	 */
	private $queryFixer;

	/**
	 * @var array|null settings (lazy loaded)
	 */
	private $profile;

	/**
	 * PhraseSuggestFallbackMethod constructor.
	 * @param SearcherFactory $factory
	 * @param SearchQuery $query
	 */
	public function __construct( SearcherFactory $factory, SearchQuery $query ) {
		$this->searcherFactory = $factory;
		$this->query = $query;
		$this->queryFixer = new QueryFixer( $query->getParsedQuery() );
	}

	/**
	 * @param SearcherFactory $factory
	 * @param SearchQuery $query
	 * @return FallbackMethod
	 */
	public static function build( SearcherFactory $factory, SearchQuery $query ) {
		return new self( $factory, $query );
	}

	/**
	 * @param FallbackRunnerContext $context
	 * @return float
	 */
	public function successApproximation( FallbackRunnerContext $context ) {
		$firstPassResults = $context->getInitialResultSet();
		if ( !$this->haveSuggestion( $firstPassResults ) ) {
			return 0.0;
		}

		if ( $this->resultContainsFullyHighlightedMatch( $firstPassResults->getElasticaResultSet() ) ) {
			return 0.0;
		}

		if ( $this->totalHitsThresholdMet( $firstPassResults->getTotalHits() ) ) {
			return 0.0;
		}

		return 0.5;
	}

	/**
	 * @param FallbackRunnerContext $context
	 * @return ResultSet
	 */
	public function rewrite( FallbackRunnerContext $context ) {
		$firstPassResults = $context->getInitialResultSet();
		$previousSet = $context->getPreviousResultSet();

		$this->showDYMSuggestion( $firstPassResults, $previousSet );
		if ( !$this->query->isAllowRewrite()
			|| $this->resultsThreshold( $previousSet )
			|| !$this->query->getParsedQuery()->isQueryOfClass( BasicQueryClassifier::SIMPLE_BAG_OF_WORDS )
		) {
			return $previousSet;
		}

		$rewrittenQuery = SearchQueryBuilder::forRewrittenQuery( $this->query,
			$firstPassResults->getSuggestionQuery() )->build();
		$searcher = $this->searcherFactory->makeSearcher( $rewrittenQuery );
		$status = $searcher->search( $rewrittenQuery );
		if ( $status->isOK() && $status->getValue() instanceof ResultSet ) {
			/**
			 * @var ResultSet $newresults
			 */
			$newresults = $status->getValue();
			$newresults->setRewrittenQuery( $firstPassResults->getSuggestionQuery(),
				$firstPassResults->getSuggestionSnippet() );
			return $newresults;
		} else {
			return $previousSet;
		}
	}

	/**
	 * @param ResultSet $resultSet
	 * @return bool
	 */
	public function haveSuggestion( ResultSet $resultSet ) {
		return $this->findSuggestion( $resultSet ) !== null;
	}

	private function showDYMSuggestion( ResultSet $fromResultSet, ResultSet $toResultSet ) {
		$suggestion = $this->findSuggestion( $fromResultSet );
		Assert::precondition( $suggestion !== null, "showDYMSuggestion called with no suggestions available" );
		$toResultSet->setSuggestionQuery(
			$this->queryFixer->fix( $suggestion['text'] ),
			$this->queryFixer->fix( $this->escapeHighlightedSuggestion( $suggestion['highlighted'] ), true )
		);
	}

	/**
	 * Escape a highlighted suggestion coming back from Elasticsearch.
	 *
	 * @param string $suggestion suggestion from elasticsearch
	 * @return string $suggestion with html escaped _except_ highlighting pre and post tags
	 */
	private function escapeHighlightedSuggestion( $suggestion ) {
		return strtr( htmlspecialchars( $suggestion ), [
			Searcher::HIGHLIGHT_PRE_MARKER => Searcher::SUGGESTION_HIGHLIGHT_PRE,
			Searcher::HIGHLIGHT_POST_MARKER => Searcher::SUGGESTION_HIGHLIGHT_POST,
		] );
	}

	/**
	 * @param int $totalHits
	 * @return bool
	 */
	private function totalHitsThresholdMet( $totalHits ) {
		$threshold = $this->getProfile()['total_hits_threshold'] ?? -1;
		return $threshold >= 0 && $totalHits > $threshold;
	}

	/**
	 * @return array|null Suggestion options, see "options" part in
	 *      https://www.elastic.co/guide/en/elasticsearch/reference/6.4/search-suggesters.html
	 */
	private function findSuggestion( ResultSet $resultSet ) {
		// TODO some kind of weighting?
		$response = $resultSet->getElasticResponse();
		if ( $response === null ) {
			return null;
		}
		$suggest = $response->getData();
		if ( !isset( $suggest[ 'suggest' ] ) ) {
			return null;
		}
		$suggest = $suggest[ 'suggest' ];
		// Elasticsearch will send back the suggest element but no sub suggestion elements if the wiki is empty.
		// So we should check to see if they exist even though in normal operation they always will.
		if ( isset( $suggest['suggest'][0] ) ) {
			foreach ( $suggest['suggest'][0][ 'options' ] as $option ) {
				return $option;
			}
		}
		return null;
	}

	/**
	 * @return array|null
	 */
	public function getSuggestQueries() {
		if ( $this->query->isWithDYMSuggestion()
				&& $this->query->getSearchConfig()->get( 'CirrusSearchEnablePhraseSuggest' )
				&& $this->query->getOffset() === 0
		) {
			$term = $this->queryFixer->getFixablePart();
			if ( $term !== null ) {
				return [
					'suggest' => [
						'text' => $term,
						'suggest' => $this->buildSuggestConfig(),
					]
				];
			}
		}
		return null;
	}

	/**
	 * Build suggest config for 'suggest' field.
	 *
	 * @return array[] array of Elastica configuration
	 */
	private function buildSuggestConfig() {
		$field = 'suggest';
		$config = $this->query->getSearchConfig();
		$suggestSettings = $this->getProfile();
		$settings = [
			'phrase' => [
				'field' => $field,
				'size' => 1,
				'max_errors' => $suggestSettings['max_errors'],
				'confidence' => $suggestSettings['confidence'],
				'real_word_error_likelihood' => $suggestSettings['real_word_error_likelihood'],
				'direct_generator' => [
					[
						'field' => $field,
						'suggest_mode' => $suggestSettings['mode'],
						'max_term_freq' => $suggestSettings['max_term_freq'],
						'min_doc_freq' => $suggestSettings['min_doc_freq'],
						'prefix_length' => $suggestSettings['prefix_length'],
					],
				],
				'highlight' => [
					'pre_tag' => Searcher::HIGHLIGHT_PRE_MARKER,
					'post_tag' => Searcher::HIGHLIGHT_POST_MARKER,
				],
			],
		];
		// Add a second generator with the reverse field
		// Only do this for local queries, we don't know if it's activated
		// on other wikis.
		if ( $config->getElement( 'CirrusSearchPhraseSuggestReverseField', 'use' )
			&& ( !$this->query->getCrossSearchStrategy()->isExtraIndicesSearchSupported()
				|| empty( OtherIndexes::getExtraIndexesForNamespaces(
					$config,
					$this->query->getNamespaces()
				)
			 ) )
		) {
			$settings['phrase']['direct_generator'][] = [
				'field' => $field . '.reverse',
				'suggest_mode' => $suggestSettings['mode'],
				'max_term_freq' => $suggestSettings['max_term_freq'],
				'min_doc_freq' => $suggestSettings['min_doc_freq'],
				'prefix_length' => $suggestSettings['prefix_length'],
				'pre_filter' => 'token_reverse',
				'post_filter' => 'token_reverse'
			];
		}
		if ( !empty( $suggestSettings['collate'] ) ) {
			$collateFields = [ 'title.plain', 'redirect.title.plain' ];
			if ( $config->get( 'CirrusSearchPhraseSuggestUseText' ) ) {
				$collateFields[] = 'text.plain';
			}
			$settings['phrase']['collate'] = [
				'query' => [
					'inline' => [
						'multi_match' => [
							'query' => '{{suggestion}}',
							'operator' => 'or',
							'minimum_should_match' => $suggestSettings['collate_minimum_should_match'],
							'type' => 'cross_fields',
							'fields' => $collateFields
						],
					],
				],
			];
		}
		if ( isset( $suggestSettings['smoothing_model'] ) ) {
			$settings['phrase']['smoothing'] = $suggestSettings['smoothing_model'];
		}

		return $settings;
	}

	/**
	 * @return array
	 */
	private function getProfile() {
		if ( $this->profile === null ) {
			$this->profile = $this->query->getSearchConfig()->getProfileService()
				->loadProfile( SearchProfileService::PHRASE_SUGGESTER );
		}
		return $this->profile;
	}
}
