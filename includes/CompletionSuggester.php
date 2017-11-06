<?php

namespace CirrusSearch;

use CirrusSearch\Query\CompSuggestQueryBuilder;
use CirrusSearch\Query\PrefixSearchQueryBuilder;
use Elastica\Exception\ExceptionInterface;
use Elastica\Index;
use Elastica\Multi\ResultSet;
use Elastica\Multi\Search as MultiSearch;
use Elastica\Query;
use CirrusSearch\Search\SearchContext;
use Elastica\Search;
use MediaWiki\MediaWikiServices;
use SearchSuggestionSet;
use Status;
use User;

/**
 * Performs search as you type queries using Completion Suggester.
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

/**
 * Completion Suggester Searcher
 *
 * NOTES:
 * The CompletionSuggester is built on top of the ElasticSearch Completion
 * Suggester.
 * (https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-completion.html).
 *
 * This class is used at query time, see
 * CirrusSearch\BuildDocument\SuggestBuilder for index time logic.
 *
 * Document model: Cirrus documents are indexed with 2 suggestions:
 *
 * 1. The title suggestion (and close redirects).
 * This helps to avoid displaying redirects with typos (e.g. Albert Enstein,
 * Unietd States) where we make the assumption that if the redirect is close
 * enough it's likely a typo and it's preferable to display the canonical title.
 * This decision is made at index-time in SuggestBuilder::extractTitleAndSimilarRedirects.
 *
 * 2. The redirect suggestions
 * Because the same canonical title can be returned twice we support fetch_limit_factor
 * in suggest profiles to fetch more than what the use asked.
 */
class CompletionSuggester extends ElasticsearchIntermediary {
	/**
	 * @const string multisearch key to identify the comp suggest request
	 */
	const MSEARCH_KEY_SUGGEST = "suggest";

	/**
	 * @var integer maximum number of result (final)
	 */
	private $limit;

	/**
	 * @var integer offset (final)
	 */
	private $offset;

	/**
	 * @var string index base name to use (final)
	 */
	private $indexBaseName;

	/**
	 * @var Index (final)
	 */
	private $completionIndex;

	/**
	 * Search environment configuration (final)
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var SearchContext (final)
	 */
	private $searchContext;

	/**
	 * @var CompSuggestQueryBuilder $compSuggestBuilder (final)
	 */
	private $compSuggestBuilder;

	/**
	 * @var PrefixSearchQueryBuilder $prefixSearchQueryBuilder (final)
	 */
	private $prefixSearchQueryBuilder;

	/**
	 * @param Connection $conn
	 * @param int $limit Limit the results to this many
	 * @param int $offset the offset
	 * @param SearchConfig $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|bool $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 * @param string|null $profileName
	 */
	public function __construct( Connection $conn, $limit, $offset = 0, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false, $profileName = null ) {
		if ( is_null( $config ) ) {
			// @todo connection has an embedded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ) );
		$this->config = $config;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->indexBaseName = $index ?: $config->get( SearchConfig::INDEX_BASE_NAME );
		$this->completionIndex = $this->connection->getIndex( $this->indexBaseName,
			Connection::TITLE_SUGGEST_TYPE );
		$this->searchContext = new SearchContext( $this->config, $namespaces );

		if ( $profileName == null ) {
			$profileName = $this->config->get( 'CirrusSearchCompletionSettings' );
		}
		$this->compSuggestBuilder = new CompSuggestQueryBuilder(
			$this->searchContext,
			$this->config->getElement( 'CirrusSearchCompletionProfiles', $profileName ),
			$limit,
			$offset
		);
		$this->prefixSearchQueryBuilder = new PrefixSearchQueryBuilder();
	}

	/**
	 * Produce a set of completion suggestions for text using _suggest
	 * See https://www.elastic.co/guide/en/elasticsearch/reference/1.6/search-suggesters-completion.html
	 *
	 * WARNING: experimental API
	 *
	 * @param string $text Search term
	 * @param string[]|null $variants Search term variants
	 * (usually issued from $wgContLang->autoConvertToAllVariants( $text ) )
	 * @return Status
	 */
	public function suggest( $text, $variants = null ) {
		$suggestSearch = $this->getSuggestSearchRequest( $text, $variants );
		$msearch = new MultiSearch( $this->connection->getClient() );
		if ( $suggestSearch !== null ) {
			$msearch->addSearch( $suggestSearch, self::MSEARCH_KEY_SUGGEST );
		}

		if ( empty( $msearch->getSearches() ) ) {
			return Status::newGood( SearchSuggestionSet::emptySuggestionSet() );
		}

		$this->connection->setTimeout( $this->config->getElement( 'wgCirrusSearchClientSideSearchTimeout', 'default' ) );
		$result = Util::doPoolCounterWork(
			'CirrusSearch-Completion',
			$this->user,
			function () use( $msearch, $text ) {
				$log = $this->newLog( "{queryType} search for '{query}'", "comp_suggest", [
					'query' => $text,
					'offset' => $this->offset,
				] );
				$this->start( $log );
				try {
					$results = $msearch->search();
					if ( $results->hasError() ||
						// Catches HTTP errors (ex: 5xx) not reported
						// by hasError()
						!$results->getResponse()->isOk()
					) {
						return $this->multiFailure( $results );
					}
					return $this->success( $this->processMSearchResponse( $results, $log ) );
				} catch ( ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			}
		);
		return $result;
	}

	/**
	 * @param ResultSet $results
	 * @param CompletionRequestLog $log
	 * @return SearchSuggestionSet
	 */
	private function processMSearchResponse( ResultSet $results, CompletionRequestLog $log ) {
		if ( isset( $results->getResultSets()[self::MSEARCH_KEY_SUGGEST] ) ) {
			$suggestSet = $this->compSuggestBuilder->postProcess(
				$results->getResultSets()[self::MSEARCH_KEY_SUGGEST],
				$this->completionIndex->getName(),
				$log
			);

			return $suggestSet;
		}
		return SearchSuggestionSet::emptySuggestionSet();
	}

	/**
	 * @param string $text Search term
	 * @param string[]|null $variants Search term variants
	 * (usually issued from $wgContLang->autoConvertToAllVariants( $text ) )
	 * @return Search|null
	 */
	private function getSuggestSearchRequest( $text, $variants ) {
		if ( !$this->compSuggestBuilder->areResultsPossible() ) {
			return null;
		}

		$suggest = $this->compSuggestBuilder->build( $text, $variants );
		$query = new Query( new Query\MatchNone() );
		$query->setSize( 0 );
		$query->setSuggest( $suggest );
		$query->setSource( [ 'target_title' ] );
		$search = new Search( $this->connection->getClient() );
		$search->addIndex( $this->completionIndex );
		$search->setQuery( $query );
		return $search;
	}

	/**
	 * @param string $description
	 * @param string $queryType
	 * @param string[] $extra
	 * @return CompletionRequestLog
	 */
	protected function newLog( $description, $queryType, array $extra = [] ) {
		return new CompletionRequestLog(
			$description,
			$queryType,
			$extra
		);
	}

	/**
	 * @return Index
	 */
	public function getCompletionIndex() {
		return $this->completionIndex;
	}
}
