<?php

namespace CirrusSearch;

trait SearcherTrait {
	/**
	 * @return Connection
	 */
	abstract protected function getConnection(): Connection;

	/**
	 * @param string $description A psr-3 compliant string describing the request
	 * @param string $queryType The type of search being performed such as
	 * fulltext, get, etc.
	 * @param array $extra A map of additional request-specific data
	 * @param array|null $namespaces set of namespaces if known
	 * @return SearchRequestLog
	 * @see ElasticsearchIntermediary::newLog()
	 */
	protected function newLog(
		$description,
		$queryType,
		array $extra = [],
		?array $namespaces = null
	): SearchRequestLog {
		return new SearchRequestLog(
			$this->getConnection()->getClient(),
			$description,
			$queryType,
			$extra,
			$namespaces
		);
	}
}
