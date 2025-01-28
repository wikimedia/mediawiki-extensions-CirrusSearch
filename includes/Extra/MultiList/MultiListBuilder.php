<?php

namespace CirrusSearch\Extra\MultiList;

use Wikimedia\Assert\Assert;

/**
 * Utility class for encoding weighted tags.
 *
 * @see https://wikitech.wikimedia.org/wiki/Search/WeightedTags
 */
class MultiListBuilder {

	private const WEIGHTED_TAG_DEFAULT_NAME = 'exists';

	/**
	 * @param string $tagPrefix A prefix shared by all `$tagNames`
	 * @param null|null[]|int[] $tagWeightsByName Optional tag weights. A map of optional weights, keyed by tag name.
	 *   Omit for tags which are fully defined by their prefix.
	 *   A single weight ranges between 1-1000.
	 *
	 * @return MultiListWeightedTag[]
	 */
	public static function buildWeightedTags(
		string $tagPrefix,
		?array $tagWeightsByName = null
	): array {
		Assert::precondition(
			trim( $tagPrefix ) !== '',
			"invalid tag prefix [$tagPrefix]: cannot be empty"
		);
		Assert::precondition(
			strpos( $tagPrefix, MultiListItem::DELIMITER ) === false,
			"invalid tag prefix $tagPrefix: must not contain " . MultiListItem::DELIMITER
		);

		Assert::parameterType(
			[
				'array',
				'null'
			],
			$tagWeightsByName,
			'$tagWeightsByName'
		);

		if ( $tagWeightsByName === null ) {
			$tagWeightsByName = [ self::WEIGHTED_TAG_DEFAULT_NAME => null ];
		}

		foreach ( $tagWeightsByName as $tagName => $tagWeight ) {
			Assert::precondition(
				strpos( $tagName, MultiListWeightedTag::WEIGHT_DELIMITER ) === false,
				"invalid tag name $tagName: must not contain " . MultiListWeightedTag::WEIGHT_DELIMITER
			);
			if ( $tagWeight !== null ) {
				Assert::precondition(
					is_int( $tagWeight ),
					"weights must be integers but $tagWeight is " . get_debug_type( $tagWeight )
				);
				Assert::precondition(
					$tagWeight >= 1 && $tagWeight <= 1000,
					"weights must be between 1 and 1000 (found: $tagWeight)"
				);
			}
		}

		return array_map(
			static fn ( $tagName ) => new MultiListWeightedTag(
				$tagPrefix, $tagName, $tagWeightsByName[$tagName]
			),
			array_keys( $tagWeightsByName )
		);
	}
}
