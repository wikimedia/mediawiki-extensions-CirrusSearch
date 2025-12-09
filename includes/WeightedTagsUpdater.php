<?php

namespace CirrusSearch;

use CirrusSearch\Extra\MultiList\MultiListBuilder;
use MediaWiki\Page\ProperPageIdentity;

/**
 * Interface for changing weighted tags.
 *
 * @license GPL-2.0-or-later
 */
interface WeightedTagsUpdater {

	public const SERVICE = self::class;

	/**
	 * Request the setting of the {@link WeightedTagsHooks::FIELD_NAME} field for the given tag(s) and weight(s).
	 * Will set a `$tagPrefix/$tagName|$tagWeight` tag for each element of `$tagNames`, and will unset
	 * all other tags with the same prefix (in other words, this will replace the existing
	 * tag set for a given prefix).
	 *
	 * @param ProperPageIdentity $page
	 * @param string $tagPrefix A prefix shared by all `$tagNames`
	 * @param null|null[]|int[] $tagWeights Optional tag weights. A map of optional weights, keyed by tag name.
	 * *   Omit for tags which are fully defined by their prefix.
	 * *   A single weight ranges between 1-1000.
	 * @param 'revision'|null $trigger Optional indicator what triggered this update,
	 *     this hint is currently only processed by {@link EventBusWeightedTagsUpdater}
	 *
	 * @throws WeightedTagsException if sending the event fails
	 *
	 * @see MultiListBuilder for parameter details
	 */
	public function updateWeightedTags(
		ProperPageIdentity $page,
		string $tagPrefix,
		?array $tagWeights = null,
		?string $trigger = null
	): void;

	/**
	 * Request the reset of the {@link WeightedTagsHooks::FIELD_NAME} field for all tags prefixed with any of the
	 * `$tagPrefixes`.
	 *
	 * @param ProperPageIdentity $page
	 * @param string[] $tagPrefixes
	 * @param 'revision'|null $trigger optionally indicate what triggered this update,
	 *                                 this hint is currently only processed by {@link EventBusWeightedTagsUpdater}
	 *
	 * @throws WeightedTagsException if sending the event fails
	 *
	 * @see WeightedTagsBuilder for parameter details
	 */
	public function resetWeightedTags(
		ProperPageIdentity $page,
		array $tagPrefixes,
		?string $trigger = null
	): void;
}
