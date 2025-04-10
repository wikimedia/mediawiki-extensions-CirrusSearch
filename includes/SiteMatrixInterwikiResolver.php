<?php

namespace CirrusSearch;

use MediaWiki\Extension\SiteMatrix\SiteMatrix;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * InterwikiResolver suited for WMF context and uses SiteMatrix.
 */
class SiteMatrixInterwikiResolver extends BaseInterwikiResolver {

	private const MATRIX_CACHE_TTL = 600;

	public function __construct(
		SearchConfig $config,
		MultiHttpClient $client,
		WANObjectCache $wanCache,
		InterwikiLookup $iwLookup
	) {
		parent::__construct( $config, $client, $wanCache, $iwLookup );
		if ( $config->getWikiId() !== WikiMap::getCurrentWikiId() ) {
			throw new \RuntimeException( "This resolver cannot with an external wiki config. (config: " .
				$config->getWikiId() . ", global: " . WikiMap::getCurrentWikiId() );
		}
	}

	/**
	 * @param SearchConfig $config
	 * @param ExtensionRegistry|null $extensionRegistry
	 * @return bool true if this resolver can run with the specified config
	 */
	public static function accepts( SearchConfig $config, ?ExtensionRegistry $extensionRegistry = null ) {
		$extensionRegistry ??= ExtensionRegistry::getInstance();
		return $config->getWikiId() === WikiMap::getCurrentWikiId()
			&& $extensionRegistry->isLoaded( 'SiteMatrix' )
			&& $config->has( 'SiteMatrixSites' );
	}

	/** @inheritDoc */
	protected function loadMatrix() {
		$cacheKey = $this->wanCache->makeKey( 'cirrussearch-interwiki-matrix', 'v1' );
		$matrix = $this->wanCache->getWithSetCallback(
			$cacheKey,
			self::MATRIX_CACHE_TTL,
			$this->siteMatrixLoader()
		);
		if ( !is_array( $matrix ) ) {
			// Should we log something if we failed?
			return [];
		}
		return $matrix;
	}

	/**
	 * @return callable
	 */
	private function siteMatrixLoader() {
		return function () {
			global $wgConf;

			$matrix = new SiteMatrix;
			$wikiDBname = $this->config->get( 'DBname' );
			[ , $myLang ] = $wgConf->siteFromDB( $wikiDBname );
			$siteConf = $this->config->get( 'SiteMatrixSites' );
			$prefixOverrides = $this->config->get( 'CirrusSearchInterwikiPrefixOverrides' );
			$sisterProjects = [];
			$crossLanguage = [];
			$prefixesByWiki = [];
			$languageMap = [];
			$myProject = null;

			if ( !in_array( $myLang, $matrix->getLangList() ) ) {
				return [];
			}

			foreach ( $matrix->getSites() as $site ) {
				if ( $matrix->getDBName( $myLang, $site ) === $wikiDBname ) {
					$myProject = $site;
					break;
				}
			}

			if ( $myProject === null ) {
				// This is not a "project"
				return [];
			}

			foreach ( $matrix->getSites() as $site ) {
				if ( $site === $myProject ) {
					continue;
				}
				if ( !$matrix->exist( $myLang, $site ) ) {
					continue;
				}
				if ( $matrix->isClosed( $myLang, $site ) ) {
					continue;
				}
				if ( !isset( $siteConf[$site]['prefix'] ) ) {
					continue;
				}
				$dbName = $matrix->getDBName( $myLang, $site );
				$prefix = $siteConf[$site]['prefix'];

				if ( isset( $prefixOverrides[$prefix] ) ) {
					$prefix = $prefixOverrides[$prefix];
				}

				if ( !in_array( $prefix, $this->config->get( 'CirrusSearchCrossProjectSearchBlockList' ) ) ) {
					$sisterProjects[$prefix] = $dbName;
				}
				$prefixesByWiki[$dbName] = $prefix;
			}

			foreach ( $matrix->getLangList() as $lang ) {
				if ( $lang === $myLang ) {
					continue;
				}

				$dbname = $matrix->getDBName( $lang, $myProject );
				if ( !$matrix->exist( $lang, $myProject ) ) {
					continue;
				}
				if ( $matrix->isClosed( $lang, $myProject ) ) {
					continue;
				}
				// Bold assumption that the interwiki prefix is equal
				// to the language.
				$iw = $this->interwikiLookup->fetch( $lang );
				// Not a valid interwiki prefix...
				if ( !$iw ) {
					continue;
				}

				$url = $matrix->getCanonicalUrl( $lang, $myProject );
				$iwurl = $iw->getURL();
				if ( strlen( $url ) > strlen( $iwurl ) ) {
					continue;
				}
				if ( substr_compare( $iwurl, $url, 0, strlen( $url ) ) !== 0 ) {
					continue;
				}

				$crossLanguage[$lang] = $dbname;
				// In theory it's impossible to override something here
				// should we log something if the case?
				$prefixesByWiki[$dbname] = $lang;
				$wikiLangCode = $wgConf->get( 'wgLanguageCode', $dbname, $myProject,
					[ 'lang' => $lang, 'site' => $myProject ] );
				$languageMap[$wikiLangCode][] = $lang;
			}
			// Cleanup unambiguous languages
			$cleanLanguageMap = [];
			foreach ( $languageMap as $lang => $dbprefixes ) {
				if ( array_key_exists( $lang, $dbprefixes )
					&& ( $dbprefixes === [ $lang ] )
				) {
					continue;
				}
				// if lang is equals to one of the dbprefixes then
				if ( in_array( $lang, $dbprefixes ) ) {
					continue;
				}
				if ( count( $dbprefixes ) > 1 ) {
					// TODO: Log this ambiguous entry
				}
				$cleanLanguageMap[$lang] = reset( $dbprefixes );
			}
			return [
				'sister_projects' => $sisterProjects,
				'language_map' => $cleanLanguageMap,
				'cross_language' => $crossLanguage,
				'prefixes_by_wiki' => $prefixesByWiki,
			];
		};
	}

}
