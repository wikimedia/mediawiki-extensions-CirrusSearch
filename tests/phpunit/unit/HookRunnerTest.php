<?php

namespace CirrusSearch\Tests\Unit;

use CirrusSearch\CirrusSearchHookRunner;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;

/**
 * @covers \CirrusSearch\CirrusSearchHookRunner
 */
class HookRunnerTest extends HookRunnerTestBase {

	public static function provideHookRunners() {
		yield CirrusSearchHookRunner::class => [ CirrusSearchHookRunner::class ];
	}

}
