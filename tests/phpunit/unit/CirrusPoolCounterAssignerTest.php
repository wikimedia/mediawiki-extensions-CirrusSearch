<?php

namespace CirrusSearch;

use MediaWiki\Exception\MWException;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;

/**
 * @license GPL-2.0-or-later
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\CirrusPoolCounterAssigner
 */
class CirrusPoolCounterAssignerTest extends CirrusTestCase {

	private const AUTOMATED_USER_AGENT = 'Mozilla/1.2 HeadlessChrome/8.7.6';

	public static function providePoolCounterType(): iterable {
		// expected pool, syntax used, offset, limit, automated user agent
		yield 'default pool' => [ PoolCounterKey::SEARCH, [], 0, 20, false ];
		yield 'unrelated syntax' => [ PoolCounterKey::SEARCH, [ 'intitle' ], 0, 20, false ];
		yield 'deepcategory' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [ 'deepcategory' ], 0, 20, false ];
		yield 'regex' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [ 'regex' ], 0, 20, false ];
		yield 'prefix' => [ PoolCounterKey::PREFIX, [ 'prefix' ], 0, 20, false ];
		yield 'more_like' => [ PoolCounterKey::MORE_LIKE, [ 'more_like' ], 0, 20, false ];
		yield 'semantic' => [ PoolCounterKey::SEMANTIC, [ 'semantic' ], 0, 20, false ];
		yield 'regex wins over prefix' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [ 'prefix', 'regex' ], 0, 20, false ];
		yield 'more_like wins over semantic' => [ PoolCounterKey::MORE_LIKE, [ 'semantic', 'more_like' ], 0, 20, false ];
		yield 'below deep pagination' => [ PoolCounterKey::SEARCH, [], 979, 20, false ];
		yield 'deep pagination' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [], 980, 20, false ];
		yield 'deep pagination from limit' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [], 0, 1000, false ];
		yield 'deep pagination overrides syntax' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [ 'prefix' ], 980, 20, false ];
		yield 'automated' => [ PoolCounterKey::AUTOMATED, [], 0, 20, true ];
		yield 'automated overrides syntax' => [ PoolCounterKey::AUTOMATED, [ 'more_like' ], 0, 20, true ];
		yield 'automated does not override regex' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [ 'regex' ], 0, 20, true ];
		yield 'automated does not override deepcategory' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, [ 'deepcategory' ], 0, 20, true ];
		yield 'automated stays automated on deep pagination' => [ PoolCounterKey::AUTOMATED, [], 980, 20, true ];
	}

	/**
	 * @dataProvider providePoolCounterType
	 */
	public function testGetPoolCounterType(
		string $expected,
		array $syntaxUsed,
		int $offset,
		int $limit,
		bool $automated
	) {
		$request = new FauxRequest();
		$request->setIP( '4.3.2.1' );
		if ( $automated ) {
			$request->setHeader( 'User-Agent', self::AUTOMATED_USER_AGENT );
		}
		$assigner = $this->newAssigner( $request, [], $syntaxUsed, $offset, $limit );
		$this->assertSame( $expected, $assigner->getPoolCounterType() );
	}

	public static function provideLooksLikeAutomation(): array {
		return [
			'no config, no problem' => [
				false, null, [], '1.2.3.4', [],
			],
			'ua match' => [
				true, [ 'user-agent' => '/HeadlessChrome/' ], [], '1.2.3.4',
				[ 'User-Agent' => 'Mozilla/1.2 HeadlessChrome/8.7.6' ]
			],
			'no ua match' => [
				false, [ 'user-agent' => '/HeadlessChrome/' ], [], '1.2.3.4',
				[ 'User-Agent' => 'Mozilla/3.4 Chrome/5.4.3' ]
			],
			'arbitrary header match' => [
				true, [ 'X-Public-Cloud' => '/^abc$/' ], [], '1.2.3.4',
				[ 'X-Public-Cloud' => 'abc' ],
			],
			'arbitrary header no-match' => [
				false, [ 'X-Public-Cloud' => '/^abc$/' ], [], '1.2.3.4',
				[ 'X-Public-Cloud' => 'xyz' ],
			],
			'cidr ipv4 match' => [
				true, null, [ '1.0.0.0/8' ], '1.2.3.4', [],
			],
			'cidr ipv4 no match' => [
				false, null, [ '1.0.0.0/8' ], '4.3.2.1', [],
			],
			'cidr ipv6 match' => [
				true, null, [ '1:2::/32' ], '1:2:3::4', [],
			],
			'cidr ipv6 no match' => [
				false, null, [ '1:2::/32' ], '4:3:2::1', [],
			],
		];
	}

	/**
	 * @dataProvider provideLooksLikeAutomation
	 */
	public function testLooksLikeAutomation(
		bool $expectAutomated,
		?array $headerRegexes,
		array $ranges,
		string $ip,
		array $headers
	) {
		$request = new FauxRequest();
		$request->setIP( $ip );
		$request->setHeaders( $headers );
		$assigner = $this->newAssigner( $request, [
			CirrusConfigNames::AutomationHeaderRegexes => $headerRegexes,
			CirrusConfigNames::AutomationCIDRs => $ranges,
		] );
		$this->assertSame(
			$expectAutomated ? PoolCounterKey::AUTOMATED : PoolCounterKey::SEARCH,
			$assigner->getPoolCounterType()
		);
	}

	public static function provideDeepWebScraping(): iterable {
		// expected pool, entry point, has cookie, offset, syntax used
		yield 'web without cookie' => [ PoolCounterKey::AUTOMATED, 'index', false, 80, [] ];
		yield 'web without cookie below threshold' => [ PoolCounterKey::SEARCH, 'index', false, 79, [] ];
		yield 'web with cookie' => [ PoolCounterKey::SEARCH, 'index', true, 80, [] ];
		yield 'api without cookie' => [ PoolCounterKey::SEARCH, 'api', false, 80, [] ];
		yield 'web without cookie on deep pagination' => [ PoolCounterKey::AUTOMATED, 'index', false, 980, [] ];
		yield 'web without cookie with regex' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, 'index', false, 80, [ 'regex' ] ];
	}

	/**
	 * @dataProvider provideDeepWebScraping
	 */
	public function testDeepWebScraping(
		string $expected,
		string $entryPoint,
		bool $hasCookie,
		int $offset,
		array $syntaxUsed
	) {
		$request = new FauxRequest();
		$request->setIP( '4.3.2.1' );
		if ( $hasCookie ) {
			$request->setHeader( 'Cookie', 'session=abc' );
		}
		$assigner = $this->newAssigner( $request, [], $syntaxUsed, $offset, 20, $entryPoint );
		$this->assertSame( $expected, $assigner->getPoolCounterType() );
	}

	public static function provideNoIp(): iterable {
		// expected pool, entry point, offset
		yield 'cli' => [ PoolCounterKey::SEARCH, 'cli', 0 ];
		yield 'api' => [ PoolCounterKey::SEARCH, 'api', 0 ];
		yield 'cli with deep pagination' => [ PoolCounterKey::EXPENSIVE_FULL_TEXT, 'cli', 980 ];
		yield 'web without cookie' => [ PoolCounterKey::AUTOMATED, 'index', 80 ];
	}

	/**
	 * @dataProvider provideNoIp
	 */
	public function testNoIp( string $expected, string $entryPoint, int $offset ) {
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willThrowException( new MWException( 'Unable to determine IP.' ) );
		$request->method( 'getHeader' )->willReturn( false );
		// The headers match the automation regex, but without an IP the header check is skipped
		$request->method( 'getAllHeaders' )->willReturn( [ 'USER-AGENT' => self::AUTOMATED_USER_AGENT ] );
		$assigner = $this->newAssigner( $request, [], [], $offset, 20, $entryPoint );
		$this->assertSame( $expected, $assigner->getPoolCounterType() );
	}

	private function newAssigner(
		WebRequest $request,
		array $config = [],
		array $syntaxUsed = [],
		int $offset = 0,
		int $limit = 20,
		string $entryPoint = 'api'
	): CirrusPoolCounterAssigner {
		$config += [
			CirrusConfigNames::AutomationHeaderRegexes => [ 'User-Agent' => '/HeadlessChrome/' ],
			CirrusConfigNames::AutomationCIDRs => [],
		];
		return new CirrusPoolCounterAssigner(
			$request,
			new HashSearchConfig( $config ),
			$limit,
			$offset,
			static fn ( string $syntax ): bool => in_array( $syntax, $syntaxUsed, true ),
			$entryPoint
		);
	}
}
