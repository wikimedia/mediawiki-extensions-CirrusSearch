<?php

namespace CirrusSearch\Search;

use Elastica\ResultSet as ElasticaResultSet;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\Title;

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
	 * @param TitleHelper|null $titleHelper
	 */
	public function __construct( $matchedAnalyzer, ?TitleHelper $titleHelper = null ) {
		parent::__construct( $titleHelper );
		$this->matchedAnalyzer = $matchedAnalyzer;
	}

	/** @inheritDoc */
	public function getSourceFiltering() {
		return [ 'namespace', 'title', 'namespace_text', 'wiki', 'redirect' ];
	}

	/**
	 * @param array $extraHighlightFields
	 * @return array|null
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields = [] ) {
		$entireValue = [
			'type' => 'unified',
			'number_of_fragments' => 0,
		];
		$manyValues = [
			'type' => 'unified',
			'fragment_size' => 10000, // We want the whole value but more than this is crazy
			'number_of_fragments' => 30,
			'order' => 'score',
		];
		return [
			// we don't really care about the actual portion of the title that matched, the UI
			// is generally responsible for doing this.
			'pre_tags' => [ "" ],
			'post_tags' => [ "" ],
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
	 * @param ElasticaResultSet $resultSet
	 * @return array[] Array of arrays, each with optional keys:
	 *   titleMatch => a title if the title matched
	 *   redirectMatches => an array of redirect matches, one per matched redirect
	 */
	public function transformElasticsearchResult( ElasticaResultSet $resultSet ) {
		$results = [];
		foreach ( $resultSet->getResults() as $r ) {
			$results[] = $this->transformOneElasticResult( $r );
		}
		return $results;
	}

	/**
	 * Finds best title or redirect
	 * @param array $match array returned by self::transformOneElasticResult
	 * @return Title|false choose best
	 */
	public static function chooseBestTitleOrRedirect( array $match ) {
		// TODO maybe dig around in the redirect matches and find the best one?
		return $match['titleMatch'] ?? $match['redirectMatches'][0] ?? false;
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
	 * @param int[] $namespaces Prefer
	 * @return Title[] with the following keys :
	 *   titleMatch => a title if the title matched
	 *   redirectMatches => an array of redirect matches, one per matched redirect
	 */
	public function transformOneElasticResult( \Elastica\Result $r, array $namespaces = [] ) {
		$title = $this->getTitleHelper()->makeTitle( $r );
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
		if ( $redirectHighlights !== [] ) {
			$source = $r->getSource();
			$docRedirects = [];
			if ( isset( $source['redirect'] ) ) {
				foreach ( $source['redirect'] as $docRedir ) {
					$docRedirects[$docRedir['title']][] = $docRedir;
				}
			}
			foreach ( $redirectHighlights as $redirectTitleString ) {
				$resultForTitle['redirectMatches'][] = $this->resolveRedirectHighlight(
					$r, $redirectTitleString, $docRedirects, $namespaces );
			}
		}
		if ( $resultForTitle === [] ) {
			// We're not really sure where the match came from so lets just pretend it was the title.
			LoggerFactory::getInstance( 'CirrusSearch' )
				->warning( "Title search result type hit a match but we can't " .
					"figure out what caused the match: {namespace}:{title}",
					[ 'namespace' => $r->namespace, 'title' => $r->title ] );
			$resultForTitle['titleMatch'] = $title;
		}

		return $resultForTitle;
	}

	/**
	 * @param \Elastica\Result $r Elasticsearch result
	 * @param string $redirectTitleString Highlighted string returned from elasticsearch
	 * @param array $docRedirects Map from title string to list of redirects from elasticsearch source document
	 * @param int[] $namespaces Prefered namespaces to source redirects from
	 * @return Title
	 */
	private function resolveRedirectHighlight( \Elastica\Result $r, $redirectTitleString, array $docRedirects, $namespaces ) {
		// The match was against a redirect so we should replace the $title with one that
		// represents the redirect.
		if ( !isset( $docRedirects[$redirectTitleString] ) ) {
			// Instead of getting the redirect's real namespace we're going to just use the namespace
			// of the title.  This is not great.
			// TODO: Should we just bail at this point?
			return $this->getTitleHelper()->makeRedirectTitle( $r, $redirectTitleString, $r->namespace );
		}

		$redirs = $docRedirects[$redirectTitleString];
		if ( count( $redirs ) === 1 ) {
			// may or may not be the right namespace, but we don't seem to have any other options.
			return $this->getTitleHelper()->makeRedirectTitle( $r, $redirectTitleString, $redirs[0]['namespace'] );
		}

		if ( $namespaces ) {
			foreach ( $redirs as $redir ) {
				if ( array_search( $redir['namespace'], $namespaces ) ) {
					return $this->getTitleHelper()->makeRedirectTitle( $r, $redirectTitleString, $redir['namespace'] );
				}
			}
		}
		// Multiple redirects with same text from different namespaces, but none of them match the requested namespaces. What now?
		return $this->getTitleHelper()->makeRedirectTitle( $r, $redirectTitleString, $redirs[0]['namespace'] );
	}
}
