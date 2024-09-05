<?php

namespace CirrusSearch;

use CirrusSearch\Extra\MultiList\MultiListBuilder;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPageFactory;

class EventBusWeightedTagsUpdater implements WeightedTagsUpdater {

	private EventBusFactory $eventBusFactory;
	private EventBusWeightedTagSerializer $eventSerializer;
	private WikiPageFactory $wikiPageFactory;

	/**
	 * @param EventBusFactory $eventBusFactory
	 * @param EventBusWeightedTagSerializer $eventSerializer
	 * @param WikiPageFactory $wikiPageFactory
	 */
	public function __construct(
		EventBusFactory $eventBusFactory,
		EventBusWeightedTagSerializer $eventSerializer,
		WikiPageFactory $wikiPageFactory
	) {
		$this->eventBusFactory = $eventBusFactory;
		$this->eventSerializer = $eventSerializer;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function resetWeightedTags(
		ProperPageIdentity $page,
		array $tagPrefixes
	): void {
		$event = $this->eventSerializer->toClearEvent( $this->wikiPageFactory->newFromTitle( $page ), $tagPrefixes );
		$this->eventBusFactory->getInstanceForStream( $this->eventSerializer->getStream() )->send( $event );
	}

	/**
	 * @inheritDoc
	 */
	public function updateWeightedTags(
		ProperPageIdentity $page,
		string $tagPrefix,
		?array $tagWeights = null
	): void {
		$weightedTags = MultiListBuilder::buildWeightedTags( $tagPrefix, $tagWeights );

		$set = array_reduce( $weightedTags, static function ( $set, $weightedTag ) {
			if ( !isset( $set[$weightedTag->getPrefix()] ) ) {
				$set[$weightedTag->getPrefix()] = [];
			}
			$set[$weightedTag->getPrefix()][$weightedTag->getName()] = $weightedTag->getWeight();
			return $set;
		}, [] );

		$event = $this->eventSerializer->toSetEvent( $this->wikiPageFactory->newFromTitle( $page ), $set );
		$this->eventBusFactory->getInstanceForStream( $this->eventSerializer->getStream() )->send( $event );
	}

}
