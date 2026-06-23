<?php

namespace CirrusSearch;

/**
 * Names of the monolog channels CirrusSearch logs to, for use with
 * LoggerFactory::getInstance().
 */
class LogChannel {
	// Default channel; shares the extension name.
	public const DEFAULT = CirrusSearch::NAME;
	// Failed writes to the search index; a signal of data divergence.
	public const CHANGE_FAILED = 'CirrusSearchChangeFailed';
	// All search requests, logged at debug level.
	public const REQUESTS = 'CirrusSearchRequests';
	// Search requests that exceeded the slow-query threshold.
	public const SLOW_REQUESTS = 'CirrusSearchSlowRequests';
	// Deprecation warnings reported by the Elasticsearch backend.
	public const DEPRECATION = 'CirrusSearchDeprecation';
}
