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
	 * @param string|string[]|null $tagNames Optional tag name or list of tag names.
	 *   Each tag will be set for each target ID. Omit for tags which are fully defined by their prefix.
	 * @param int|int[]|null $tagWeights Optional tag weight(s).
	 *   * If `$tagNames` is null, an integer.
	 *   * Otherwise, a `[ tagName => weight ]` map. This may only use a subset of `$tagNames` as keys.
	 *       However, only valid keys (which exist in `$tagNames`) will result in returned tags.
	 *
	 * A single weight ranges between 1-1000.
	 *
	 * @return MultiListWeightedTag[]
	 * @deprecated use {@link buildWeightedTags} instead use {@link buildTagWeightsFromLegacyParameters} to migrate
	 */
	public static function buildWeightedTagsFromLegacyParameters(
		string $tagPrefix,
		$tagNames = null,
		$tagWeights = null
	): array {
		$tagWeights = self::buildTagWeightsFromLegacyParameters( $tagNames, $tagWeights );

		return self::buildWeightedTags( $tagPrefix, $tagWeights );
	}

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

	/**
	 * Merges `$tagNames` and `$tagWeights` into a single map that can be passed to {@link buildWeightedTags}.
	 *
	 * @param null|string|string[] $tagNames Optional tag name or list of tag names.
	 * Each tag will be set for each target ID. Omit for tags which are fully defined by their prefix.
	 * @param null|int|int[] $tagWeights Optional tag weight(s).
	 *   * If `$tagNames` is null, an integer.
	 *   * Otherwise, a `[ tagName => weight ]` map. This may only use a subset of `$tagNames` as keys.
	 *   * However, only valid keys (which exist in `$tagNames`) will result in returned tags.
	 *
	 * A single weight ranges between 1-1000.
	 *
	 * @return null[]|int[] A map of tag weights keyed by tag name
	 * @see buildWeightedTags
	 * @see buildWeightedTagsFromLegacyParameters
	 */
	public static function buildTagWeightsFromLegacyParameters( $tagNames = null, $tagWeights = null ) {
		Assert::parameterType(
			[
				'string',
				'array',
				'null'
			],
			$tagNames,
			'$tagNames'
		);
		if ( $tagNames === null ) {
			$tagNames = [ self::WEIGHTED_TAG_DEFAULT_NAME ];
			if ( $tagWeights !== null ) {
				Assert::parameterType( 'integer', $tagWeights, '$tagWeights' );
				$tagWeights = [ self::WEIGHTED_TAG_DEFAULT_NAME => $tagWeights ];
			} else {
				$tagWeights = [ self::WEIGHTED_TAG_DEFAULT_NAME => null ];
			}
		} elseif ( is_string( $tagNames ) ) {
			if ( $tagWeights === null ) {
				$tagWeights = [ $tagNames => null ];
			}

			$tagNames = [ $tagNames ];
		} elseif ( is_array( $tagNames ) ) {
			Assert::parameterElementType( 'string', $tagNames, '$tagNames' );
			if ( $tagWeights === null ) {
				$tagWeights = array_fill_keys( $tagNames, null );
			}
		}

		if ( $tagWeights ) {
			foreach ( $tagWeights as $tagName => $tagWeight ) {
				Assert::precondition(
					strpos( $tagName, MultiListWeightedTag::WEIGHT_DELIMITER ) === false,
					"invalid tag name $tagName: must not contain " . MultiListWeightedTag::WEIGHT_DELIMITER
				);
				Assert::precondition(
					in_array( $tagName, $tagNames, true ),
					"tag name $tagName used in \$tagWeights but not found in \$tagNames"
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
		}

		return $tagWeights;
	}

}
