<?php

namespace CirrusSearch;

use CirrusSearch\Extra\MultiList\MultiListBuilder;
use CirrusSearch\Search\CirrusIndexField;
use PHPUnit\Framework\TestCase;
use Wikimedia\Assert\ParameterElementTypeException;
use Wikimedia\Assert\PreconditionException;

/**
 * @covers \CirrusSearch\Extra\MultiList\MultiListBuilder
 */
class MultiListBuilderTest extends TestCase {

	public function testBuildWeightedTags() {
		$allTags = MultiListBuilder::buildWeightedTagsFromLegacyParameters(
			"prefix",
			[ "tag-a", "tag-b" ],
			[
				"tag-a" => 100, "tag-b" => 200
			]
		);

		$this->assertContains( "prefix/tag-a|100", array_map( static fn ( $wt ) => (string)$wt, $allTags ) );
		$this->assertContains( "prefix/tag-b|200", array_map( static fn ( $wt ) => (string)$wt, $allTags ) );

		$deletedTags = MultiListBuilder::buildWeightedTagsFromLegacyParameters( "prefix", CirrusIndexField::MULTILIST_DELETE_GROUPING );
		$this->assertContains(
			"prefix/" . CirrusIndexField::MULTILIST_DELETE_GROUPING,
			array_map( static fn ( $wt ) => (string)$wt, $deletedTags ) );
	}

	/**
	 * @param string $tagPrefix
	 * @param string|string[]|null $tagNames Optional tag name or list of tag names.
	 * Each tag will be set for each target ID. Omit for tags which are fully defined by their prefix.
	 * @param int|int[][]|null $tagWeights Optional tag weight(s).
	 * If `$tagNames` is null, an integer.
	 * Otherwise, a `targetId => [ tagName => weight ]` map.
	 * If omitted (null), no tag will be returned for the corresponding targetId/tag combination.
	 *
	 * A single weight has ranges between 1-1000.
	 * @param string|null $exception expected exception
	 * @param string|null $exceptionMessage expected exception message
	 * @dataProvider badArguments
	 */
	public function testBuildWeightedTagsArgumentValidation(
		string $tagPrefix,
				$tagNames = null,
				$tagWeights = null,
		?string $exception = null,
		?string $exceptionMessage = null
	) {
		if ( (bool)$exception ) {
			$this->expectException( $exception );
		}
		if ( (bool)$exceptionMessage ) {
			$this->expectExceptionMessage( $exceptionMessage );
		}
		MultiListBuilder::buildWeightedTagsFromLegacyParameters( $tagPrefix, $tagNames, $tagWeights );
	}

	/**
	 * @return array
	 */
	public function badArguments(): array {
		return [
			[ "", [ 1 ], null, ParameterElementTypeException::class ],
			[ "/bad/prefix", [], null, PreconditionException::class,
				"Precondition failed: invalid tag prefix /bad/prefix: must not contain /" ],
			[ "", [ "|bad-tag|" ], null, PreconditionException::class,
				"Precondition failed: invalid tag name |bad-tag|: must not contain |" ],
			[ "", [ "tag-a" ], [ "tag-b" => 1 ], PreconditionException::class,
				"Precondition failed: tag name tag-b used in \$tagWeights but not found in \$tagNames" ],
			[ "", [ "tag-a" ], [ "tag-a" => 5000 ], PreconditionException::class,
				"Precondition failed: weights must be between 1 and 1000 (found: 5000)" ],
			[ "", null, 5000, PreconditionException::class,
				"Precondition failed: weights must be between 1 and 1000 (found: 5000)" ],
		];
	}
}
