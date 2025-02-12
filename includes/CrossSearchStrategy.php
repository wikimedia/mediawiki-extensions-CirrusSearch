<?php

namespace CirrusSearch;

/**
 * Defines support strategies regarding cross wiki searches.
 *
 * Cross wiki search techniques include:
 *  - extra indices searches (i.e. search media files on commons)
 *  - cross project searches
 *  - cross language searches (i.e. second try attempt)
 */
class CrossSearchStrategy {
	/**
	 * @see InterwikiSearcher
	 * @var bool
	 */
	private $crossProjectSearchSupported;

	/**
	 * @see \CirrusSearch::searchTextSecondTry()
	 * @var bool
	 */
	private $crossLanguageSearchSupported;

	/**
	 * Controlled by $wgCirrusSearchExtraIndexes
	 * (Usually used to search media files on commons from any host wikis)
	 * @var bool
	 */
	private $extraIndicesSearchSupported;

	/**
	 * @var self
	 */
	private static $hostWikiOnly;

	/**
	 * @var self
	 */
	private static $allWikisStrategy;

	/**
	 * Only host wiki is supported
	 * Applies to features that are probably not available on any
	 * other target wiki cirrus may access.
	 */
	public static function hostWikiOnlyStrategy(): self {
		self::$hostWikiOnly ??= new self( false, false, false );
		return self::$hostWikiOnly;
	}

	/**
	 * Applies to features that must be available on any target wiki.
	 */
	public static function allWikisStrategy(): self {
		self::$allWikisStrategy ??= new self( true, true, true );
		return self::$allWikisStrategy;
	}

	/**
	 * @param bool $crossProjectSearchSupported
	 * @param bool $crossLanguageSearchSupported
	 * @param bool $extraIndicesSupported
	 */
	public function __construct( $crossProjectSearchSupported, $crossLanguageSearchSupported, $extraIndicesSupported ) {
		$this->crossProjectSearchSupported = $crossProjectSearchSupported;
		$this->crossLanguageSearchSupported = $crossLanguageSearchSupported;
		$this->extraIndicesSearchSupported = $extraIndicesSupported;
	}

	/**
	 * Is cross project search supported (aka interwiki search)
	 * @return bool
	 */
	public function isCrossProjectSearchSupported() {
		return $this->crossProjectSearchSupported;
	}

	/**
	 * Is cross language search supported (i.e. second try language search)
	 * @return bool
	 */
	public function isCrossLanguageSearchSupported() {
		return $this->crossLanguageSearchSupported;
	}

	/**
	 * (in WMF context it's most commonly used to search media files
	 * on commons from any wikis.)
	 *
	 * See wgCirrusSearchExtraIndexes
	 * @return bool
	 */
	public function isExtraIndicesSearchSupported() {
		return $this->extraIndicesSearchSupported;
	}

	/**
	 * Intersect this strategy with other.
	 */
	public function intersect( self $other ): self {
		if ( $other === self::hostWikiOnlyStrategy() || $this === self::hostWikiOnlyStrategy() ) {
			return self::hostWikiOnlyStrategy();
		}
		$crossL = $other->crossLanguageSearchSupported && $this->crossLanguageSearchSupported;
		$crossP = $other->crossProjectSearchSupported && $this->crossProjectSearchSupported;
		$otherI = $other->extraIndicesSearchSupported && $this->extraIndicesSearchSupported;
		if ( $crossL === $crossP && $crossP === $otherI ) {
			if ( $crossL ) {
				return self::allWikisStrategy();
			} else {
				return self::hostWikiOnlyStrategy();
			}
		}
		return new self( $crossP, $crossL, $otherI );
	}
}
