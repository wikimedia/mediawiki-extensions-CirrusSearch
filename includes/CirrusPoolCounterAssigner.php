<?php

namespace CirrusSearch;

use Closure;
use MediaWiki\Exception\MWException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Request\WebRequest;
use Wikimedia\IPUtils;

/**
 * Selects the pool counter type of a search request. The selection uses the
 * query syntax, the depth of the requested results and signals of automation.
 */
class CirrusPoolCounterAssigner {
	/**
	 * Queries with offset + limit greater than this value are considered
	 * potentially automated and may, after considering other related signals,
	 * be placed into the automated pool counter bucket.
	 */
	private const AUTOMATED_RESULT_DEPTH_THRESHOLD_SMALL = 100;

	/**
	 * Queries with offset + limit greater than this value are considered
	 * expensive and will use the expensive pool counter
	 */
	private const AUTOMATED_RESULT_DEPTH_THRESHOLD_LARGE = 1000;

	private WebRequest $request;
	private SearchConfig $config;
	private int $limit;
	private int $offset;
	/**
	 * @var Closure(string):bool
	 */
	private Closure $syntaxUsedClosure;
	private string $entryPoint;

	/**
	 * @param WebRequest $request
	 * @param SearchConfig $searchConfig
	 * @param int $limit
	 * @param int $offset
	 * @param Closure(string):bool $syntaxUsedCallback Returns true when the query uses the syntax
	 * @param string $entryPoint The MediaWiki entry point, normally MW_ENTRY_POINT
	 */
	public function __construct(
		WebRequest $request,
		SearchConfig $searchConfig,
		int $limit,
		int $offset,
		Closure $syntaxUsedCallback,
		string $entryPoint
	) {
		$this->request = $request;
		$this->config = $searchConfig;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->syntaxUsedClosure = $syntaxUsedCallback;
		$this->entryPoint = $entryPoint;
	}

	public function getPoolCounterType(): string {
		// Default pool counter for all search requests. Note that not all
		// possible requests go through Searcher, so this isn't globally
		// definitive.
		$pool = PoolCounterKey::SEARCH;
		// Pool counter overrides based on query syntax. Goal is to
		// separate expensive or high-volume traffic into dedicated
		// pools with specific limits. Prefix is only high volume
		// when completion is disabled.
		// TODO: Should this be configuration?
		$poolCounterTypes = [
			'deepcategory' => PoolCounterKey::EXPENSIVE_FULL_TEXT,
			'regex' => PoolCounterKey::EXPENSIVE_FULL_TEXT,
			'prefix' => PoolCounterKey::PREFIX,
			'more_like' => PoolCounterKey::MORE_LIKE,
			'semantic' => PoolCounterKey::SEMANTIC,
		];
		foreach ( $poolCounterTypes as $type => $counter ) {
			if ( ( $this->syntaxUsedClosure )( $type ) ) {
				$pool = $counter;
				break;
			}
		}
		// Put external automated requests into their own bucket The main idea
		// here is to allow automated access, but prevent that automation from
		// capping out the pools used by interactive queries.
		// It's not clear when the automation bucket should not override other
		// bucketing decisions, for now override everything except Regex since
		// those can be very expensive and usually use a small pool. If both
		// the automation and regex pools filled with regexes it would be
		// significantly more load than expected.
		if ( $pool !== PoolCounterKey::EXPENSIVE_FULL_TEXT && $this->looksLikeAutomation() ) {
			$pool = PoolCounterKey::AUTOMATED;
		} elseif ( $this->offset + $this->limit >= self::AUTOMATED_RESULT_DEPTH_THRESHOLD_LARGE ) {
			// Deep pagination is always expensive, but if the request was
			// already flagged as automated leave it in the automated bucket.
			// We don't want to accidently split a bot's requests into both
			// buckets, allowing them to use all the capacity of both.
			$pool = PoolCounterKey::EXPENSIVE_FULL_TEXT;
		}
		return $pool;
	}

	/**
	 * Flags requests that look potentially automated
	 *
	 * See https://phabricator.wikimedia.org/T405482
	 */
	private function isDeepWebScrapingRequest(): bool {
		// Request issued via index.php (web). We could consider api requests,
		// but the no cookie signal is only useful in the web context.
		return $this->entryPoint === 'index'
			// Request has no cookies (signal for simple bots).
			&& !$this->request->getHeader( 'Cookie' )
			// Asks for results well beyond the typical
			&& $this->offset + $this->limit >= self::AUTOMATED_RESULT_DEPTH_THRESHOLD_SMALL;
	}

	/**
	 * @return bool True when the parameters appear to be a non-interactive use case.
	 */
	private function looksLikeAutomation(): bool {
		if ( $this->isDeepWebScrapingRequest() ) {
			return true;
		}

		try {
			$ip = $this->request->getIP();
		} catch ( MWException ) {
			// No IP, typically this means a CLI invocation. We are attempting
			// to segregate external automation, internal automation has its
			// own ability to control configuration and shouldn't be flagged
			if ( $this->entryPoint === 'cli' ) {
				return false;
			}
			// When can we get here? Is this ever run?
			LoggerFactory::getInstance( LogChannel::DEFAULT )->info(
				'No IP available during automated request check' );
			return false;
		}
		// Is there an http header that can be matched with regex to flag automation,
		// such as the user-agent or a flag applied by some infrastructure?
		$headers = $this->request->getAllHeaders();
		$automationHeaders = $this->config->get( CirrusConfigNames::AutomationHeaderRegexes ) ?? [];
		foreach ( $automationHeaders as $name => $pattern ) {
			$name = strtoupper( $name );
			if ( !isset( $headers[$name] ) ) {
				continue;
			}
			$ret = preg_match( $pattern, $headers[$name] );
			if ( $ret === 1 ) {
				return true;
			} elseif ( $ret === false ) {
				LoggerFactory::getInstance( LogChannel::DEFAULT )->warning(
					"Invalid regex provided for header `$name` in `CirrusSearchAutomationHeaderRegexes`." );
			}
		}

		// Does the ip address fall into a subnet known for automation?
		$ranges = $this->config->get( CirrusConfigNames::AutomationCIDRs );
		if ( IPUtils::isInRanges( $ip, $ranges ) ) {
			return true;
		}

		// Default assumption that requests are interactive
		return false;
	}
}
