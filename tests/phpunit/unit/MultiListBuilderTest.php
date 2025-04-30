<?php

namespace CirrusSearch;

use CirrusSearch\Extra\MultiList\MultiListBuilder;
use PHPUnit\Framework\TestCase;
use Wikimedia\Assert\PreconditionException;

/**
 * @covers \CirrusSearch\Extra\MultiList\MultiListBuilder
 */
class MultiListBuilderTest extends TestCase {

	public function testBuildWeightedTags() {
		$allTags = MultiListBuilder::buildWeightedTags(
			"prefix",
			[
				"tag-a" => 100, "tag-b" => 200
			]
		);

		$this->assertContains( "prefix/tag-a|100", array_map( static fn ( $wt ) => (string)$wt, $allTags ) );
		$this->assertContains( "prefix/tag-b|200", array_map( static fn ( $wt ) => (string)$wt, $allTags ) );
	}

	/**
	 * @param string $tagPrefix
	 * @param int|int[][]|null $tagWeights Optional tag weight(s).
	 * If `$tagNames` is null, an integer.
	 * Otherwise, a `targetId => [ tagName => weight ]` map.
	 * If omitted (null), no tag will be returned for the corresponding targetId/tag combination.
	 *
	 * A single weight has ranges between 1-1000.
	 * @param string|null $exception expected exception
	 * @param string|null $exceptionMessage expected exception message
	 * @dataProvider provideBadArguments
	 */
	public function testBuildWeightedTagsArgumentValidation(
		string $tagPrefix,
				$tagWeights = null,
		?string $exception = null,
		?string $exceptionMessage = null
	) {
		if ( $exception !== null ) {
			$this->expectException( $exception );
		}
		if ( $exceptionMessage !== null ) {
			$this->expectExceptionMessage( $exceptionMessage );
		}
		MultiListBuilder::buildWeightedTags( $tagPrefix, $tagWeights );
	}

	/**
	 * @return array
	 */
	public function provideBadArguments(): array {
		return [
			[ "", [ 1 ], PreconditionException::class ],
			[ "/bad/prefix", [], PreconditionException::class,
				"Precondition failed: invalid tag prefix /bad/prefix: must not contain /" ],
			[ "prefix", [ "|bad-tag|" => null ], PreconditionException::class,
				"Precondition failed: invalid tag name |bad-tag|: must not contain |" ],
			[ "prefix", [ "tag-a" => 5000 ], PreconditionException::class,
				"Precondition failed: weights must be between 1 and 1000 (found: 5000)" ],
		];
	}
}
