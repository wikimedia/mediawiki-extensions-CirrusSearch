<?php

namespace CirrusSearch;

class CompletionRequestLog extends BaseRequestLog {

	/**
	 * @var array
	 */
	private $hits = [];

	/**
	 * @var \Elastica\Response|null
	 */
	private $response;

	/**
	 * @var float
	 */
	private $maxScore = 0.0;

	/**
	 * @var string
	 */
	private $index = '';

	/**
	 * @param string $indexName The name of the elasticsearch index the
	 *  suggestions were sourced from.
	 * @param SearchSuggestion[] $result The set of suggestion results that
	 *  will be returned to the user.
	 * @param string[] $suggestionProfileByDocId A map from elasticsearch
	 *  document id to the completion profile that provided the highest score
	 *  for that document id.
	 */
	public function setResult( $indexName, array $result, array $suggestionProfileByDocId ) {
		$maxScore = 0;
		$this->index = $indexName;
		foreach ( $result as $docId => $suggestion ) {
			$title = $suggestion->getSuggestedTitle();
			$pageId = $suggestion->getSuggestedTitleID() ?: -1;
			$maxScore = max( $maxScore, $suggestion->getScore() );
			$this->hits[] = [
				'title' => $title ? (string)$title : $suggestion->getText(),
				'index' => $indexName,
				'pageId' => (int)$pageId,
				'score' => $suggestion->getScore(),
				'profileName' => isset( $suggestionProfileByDocId[$docId] )
					? $suggestionProfileByDocId[$docId]
					: "",
			];
		}
		$this->maxScore = (float)$maxScore;
	}

	/**
	 * @param \Elastica\Response $response
	 */
	public function setResponse( \Elastica\Response $response ) {
		$this->response = $response;
	}

	/**
	 * Provides the elasticsearch response used when the completion suggester
	 * needs to do a second pass query to fetch redirects. This is optional and
	 * not all completion requests will need to perform a 2nd pass to resolve
	 * redirects.
	 *
	 * @param \Elastica\Response $response
	 */
	public function set2ndPassResponse( \Elastica\Response $response ) {
		$this->extra['elasticTook2PassMs'] = (string)round( $response->getQueryTime() * 1000 );
	}

	/**
	 * @return int
	 */
	public function getElasticTookMs() {
		if ( $this->response ) {
			return intval( $this->response->getQueryTime() * 1000 );
		} else {
			return -1;
		}
	}

	/**
	 * @return bool
	 */
	public function isCachedResponse() {
		return false;
	}

	/**
	 * @return array
	 */
	public function getLogVariables() {
		// Note this intentionally extracts data from $this->extra, rather than
		// using it directly. The use case is small enough for this class we can
		// be more explicit about returned variables.
		return [
			'query' => isset( $this->extra['query'] ) ? $this->extra['query'] : '',
			'queryType' => $this->getQueryType(),
			'index' => $this->index,
			'elasticTookMs' => $this->getElasticTookMs(),
			'elasticTook2PassMs' => isset( $this->extra['elasticTook2PassMs'] ) ? $this->extra['elasticTook2PassMs'] : -1,
			'hitsTotal' => $this->getTotalHits(),
			'maxScore' => $this->maxScore,
			'hitsReturned' => count( $this->hits ),
			'hitsOffset' => isset( $this->extra['offset'] ) ? $this->extra['offset'] : 0,
			'tookMs' => $this->getTookMs(),
		];
	}

	/**
	 * @return array[]
	 */
	public function getRequests() {
		$vars = $this->getLogVariables() + [
			'hits' => $this->hits,
		];

		return [ $vars ];
	}

	/**
	 * @return int The total number of hits returned by elasticsearch to
	 *  cirrussearch. This includes duplicate titles that were returned by
	 *  multiple profiles.
	 */
	private function getTotalHits() {
		if ( !$this->response ) {
			return 0;
		}
		$hitsTotal = 0;
		$data = $this->response->getData();
		if ( isset( $data['suggest'] ) ) {
			foreach ( $data['suggest'] as $type ) {
				foreach ( $type as $results ) {
					$hitsTotal += count( $results['options'] );
				}
			}
		}
		return $hitsTotal;
	}
}
