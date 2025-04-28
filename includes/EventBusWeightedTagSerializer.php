<?php

namespace CirrusSearch;

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPage;
use MediaWiki\WikiMap\WikiMap;

class EventBusWeightedTagSerializer {
	public const SCHEMA = '/mediawiki/cirrussearch/page_weighted_tags_change/1.0.0';
	public const STREAM = 'mediawiki.cirrussearch.page_weighted_tags_change.v1';

	private EventSerializer $eventSerializer;
	private PageEntitySerializer $pageEntitySerializer;
	private string $stream;

	/**
	 * @param EventSerializer $eventSerializer
	 * @param PageEntitySerializer $pageEntitySerializer
	 * @param string $stream
	 */
	public function __construct(
		EventSerializer $eventSerializer,
		PageEntitySerializer $pageEntitySerializer,
		string $stream = self::STREAM
	) {
		$this->eventSerializer = $eventSerializer;
		$this->pageEntitySerializer = $pageEntitySerializer;
		$this->stream = $stream;
	}

	/**
	 * @return string
	 */
	public function getStream(): string {
		return $this->stream;
	}

	/**
	 * @param WikiPage $wikiPage page to tag
	 * @param array $weightedTags `weighted_tags` payload, see schema
	 * @param bool|null $revBased `rev_based` flag
	 * @param string|null $dt event timestamp
	 *
	 * @return array encoded event
	 * @see self::SCHEMA
	 */
	public function toArray( WikiPage $wikiPage, array $weightedTags, ?bool $revBased = null, ?string $dt = null ): array {
		$uri = $this->pageEntitySerializer->canonicalPageURL( $wikiPage );
		$page = $this->pageEntitySerializer->toArray( $wikiPage );
		return $this->eventSerializer->createEvent(
			self::SCHEMA,
			$this->stream,
			$uri,
			array_merge( $revBased === null ? [] : [ 'rev_based' => $revBased ], [
				'dt' => EventSerializer::timestampToDt( $dt ),
				'wiki_id' => self::getWikiId( $wikiPage ),
				'page' => $page,
				'weighted_tags' => $weightedTags
			] ),
			self::getWikiId( $wikiPage )
		);
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param array[] $set prefix => [ [ 'tag' => string ] | [ 'tag' => string, 'score' => int ] ] map
	 * @param bool|null $revBased `rev_based` flag
	 * @param string|null $dt event timestamp
	 * @return array
	 */
	public function toSetEvent( WikiPage $wikiPage, array $set, ?bool $revBased = null, ?string $dt = null ): array {
		return $this->toArray( $wikiPage, [ 'set' => $set ], $revBased, $dt );
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param string[] $clear prefixes to be cleared
	 * @param bool|null $revBased `rev_based` flag
	 * @param string|null $dt event timestamp
	 * @return array
	 */
	public function toClearEvent( WikiPage $wikiPage, array $clear, ?bool $revBased = null, ?string $dt = null ): array {
		return $this->toArray( $wikiPage, [ 'clear' => $clear ], $revBased, $dt );
	}

	/**
	 * Return the page's wikiId, or if that returns false,
	 * return WikiMap::getCurrentWikiId.
	 *
	 * @param ProperPageIdentity $page
	 * @return string
	 */
	private static function getWikiId( ProperPageIdentity $page ): string {
		// Get the wikiId.  page's getWikiId can return false.
		// Fallback to global WikiMap.
		return $page->getWikiId() ?: WikiMap::getCurrentWikiId();
	}

}
