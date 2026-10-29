<?php

namespace CirrusSearch;

use Closure;
use MediaWiki\Exception\MWException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Request\WebRequest;
use Wikimedia\IPUtils;

/**
 * Selects the pool counter type of a search request.
 *
 * The selection uses an ordered list of rules, see $wgCirrusSearchPoolCounterRules.
 * The first rule with all its conditions true gives the pool. When no rule
 * matches, the request uses PoolCounterKey::SEARCH.
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

	/**
	 * Keys a rule can have, in addition to 'pool'.
	 */
	private const CONDITIONS = [ 'syntax', 'min_depth', 'entry_point', 'headers', 'missing_headers', 'ip_ranges' ];

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

	/**
	 * The rules to use when $wgCirrusSearchPoolCounterRules is null.
	 *
	 * @param SearchConfig $config Gives the legacy automation settings
	 * @return array[]
	 */
	public static function defaultRules( SearchConfig $config ): array {
		$rules = [
			// Regex and deepcategory can be very expensive and usually use a
			// small pool. Automated requests do not override this pool: if both
			// the automation and regex pools filled with regexes it would be
			// significantly more load than expected.
			[ 'pool' => PoolCounterKey::EXPENSIVE_FULL_TEXT, 'syntax' => [ 'deepcategory', 'regex' ] ],
			// Deep web scraping, see https://phabricator.wikimedia.org/T405482.
			// The no cookie signal is only useful in the web context.
			[
				'pool' => PoolCounterKey::AUTOMATED,
				'entry_point' => [ 'index' ],
				'missing_headers' => [ 'cookie' ],
				'min_depth' => self::AUTOMATED_RESULT_DEPTH_THRESHOLD_SMALL,
			],
		];
		// Put external automated requests into their own bucket. The main idea
		// here is to allow automated access, but prevent that automation from
		// capping out the pools used by interactive queries.
		$headerRegexes = $config->get( CirrusConfigNames::AutomationHeaderRegexes );
		if ( $headerRegexes ) {
			$rules[] = [ 'pool' => PoolCounterKey::AUTOMATED, 'headers' => $headerRegexes ];
		}
		$ranges = $config->get( CirrusConfigNames::AutomationCIDRs );
		if ( $ranges ) {
			$rules[] = [ 'pool' => PoolCounterKey::AUTOMATED, 'ip_ranges' => $ranges ];
		}
		return array_merge( $rules, [
			// Deep pagination is always expensive. Automated requests stay in
			// the automated bucket: we don't want to split a bot's requests
			// into both buckets, allowing them to use all the capacity of both.
			[ 'pool' => PoolCounterKey::EXPENSIVE_FULL_TEXT, 'min_depth' => self::AUTOMATED_RESULT_DEPTH_THRESHOLD_LARGE ],
			// Prefix is only high volume when completion is disabled.
			[ 'pool' => PoolCounterKey::PREFIX, 'syntax' => [ 'prefix' ] ],
			[ 'pool' => PoolCounterKey::MORE_LIKE, 'syntax' => [ 'more_like' ] ],
			[ 'pool' => PoolCounterKey::SEMANTIC, 'syntax' => [ 'semantic' ] ],
		] );
	}

	public function getPoolCounterType(): string {
		$rules = $this->config->get( CirrusConfigNames::PoolCounterRules ) ?? self::defaultRules( $this->config );
		foreach ( $rules as $index => $rule ) {
			$error = self::validateRule( $rule );
			if ( $error !== null ) {
				LoggerFactory::getInstance( LogChannel::DEFAULT )->warning(
					'Skipping invalid pool counter rule {index}: {error}',
					[ 'index' => $index, 'error' => $error ]
				);
				continue;
			}
			if ( $this->ruleMatches( $rule ) ) {
				return $rule['pool'];
			}
		}
		// Default pool counter for all search requests. Note that not all
		// possible requests go through Searcher, so this isn't globally
		// definitive.
		return PoolCounterKey::SEARCH;
	}

	/**
	 * @param mixed $rule
	 * @return string|null The error, or null when the rule is valid
	 */
	private static function validateRule( mixed $rule ): ?string {
		if ( !is_array( $rule ) ) {
			return 'the rule is not an array';
		}
		if ( !is_string( $rule['pool'] ?? null ) ) {
			return 'the rule has no pool';
		}
		$unknown = array_diff( array_keys( $rule ), [ 'pool', ...self::CONDITIONS ] );
		if ( $unknown ) {
			return 'unknown conditions: ' . implode( ', ', $unknown );
		}
		return null;
	}

	/**
	 * @param array $rule A valid rule
	 * @return bool True when all the conditions of the rule are true
	 */
	private function ruleMatches( array $rule ): bool {
		foreach ( $rule as $condition => $value ) {
			$matches = match ( $condition ) {
				'syntax' => $this->syntaxUsed( (array)$value ),
				'min_depth' => $this->offset + $this->limit >= (int)$value,
				'entry_point' => in_array( $this->entryPoint, (array)$value, true ),
				'headers' => $this->headersMatch( (array)$value ),
				'missing_headers' => $this->headersMissing( (array)$value ),
				'ip_ranges' => $this->ipInRanges( (array)$value ),
				default => true,
			};
			if ( !$matches ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string[] $syntaxes
	 * @return bool True when the query uses one of the syntaxes
	 */
	private function syntaxUsed( array $syntaxes ): bool {
		foreach ( $syntaxes as $syntax ) {
			if ( ( $this->syntaxUsedClosure )( $syntax ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $patterns Map from http header name to regex
	 * @return bool True when all of the headers matches its regex
	 */
	private function headersMatch( array $patterns ): bool {
		$headers = $this->request->getAllHeaders();
		foreach ( $patterns as $name => $pattern ) {
			$name = strtoupper( $name );
			if ( !isset( $headers[$name] ) ) {
				return false;
			}
			$ret = preg_match( $pattern, $headers[$name] );
			if ( $ret === 0 ) {
				return false;
			} elseif ( $ret === false ) {
				LoggerFactory::getInstance( LogChannel::DEFAULT )->warning(
					'Invalid regex provided for header {header} in the pool counter rules.',
					[ 'header' => $name ]
				);
				return false;
			}
		}
		return count( $patterns ) > 0;
	}

	/**
	 * @param string[] $names http header names
	 * @return bool True when the request has none of the headers
	 */
	private function headersMissing( array $names ): bool {
		foreach ( $names as $name ) {
			if ( $this->request->getHeader( $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string[] $ranges CIDRs
	 * @return bool True when the client ip is in one of the ranges
	 */
	private function ipInRanges( array $ranges ): bool {
		try {
			$ip = $this->request->getIP();
		} catch ( MWException ) {
			// No IP, typically this means a CLI invocation. We are attempting
			// to segregate external automation, internal automation has its
			// own ability to control configuration and shouldn't be flagged
			if ( $this->entryPoint !== 'cli' ) {
				// When can we get here? Is this ever run?
				LoggerFactory::getInstance( LogChannel::DEFAULT )->info(
					'No IP available during pool counter assignment' );
			}
			return false;
		}
		return IPUtils::isInRanges( $ip, $ranges );
	}
}
