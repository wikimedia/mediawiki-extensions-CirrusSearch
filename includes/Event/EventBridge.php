<?php

namespace CirrusSearch\Event;

use MediaWiki\Hook\LinksUpdateCompleteHook;

/**
 * Empty interface to help the EventBusBridge::factory method to return the same type
 * regardless of the presence of the EventBus extension.
 */
interface EventBridge extends LinksUpdateCompleteHook {

}
