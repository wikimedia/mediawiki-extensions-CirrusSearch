<?php

namespace CirrusSearch;

use CirrusSearch\Extra\MultiList\MultiListBuilder;
use MediaWiki\Page\ProperPageIdentity;

/**
 * Interface for changing weighted tags.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
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
	 * @see WeightedTagsBuilder for parameter details
	 */
	public function resetWeightedTags(
		ProperPageIdentity $page,
		array $tagPrefixes,
		?string $trigger = null
	): void;
}
