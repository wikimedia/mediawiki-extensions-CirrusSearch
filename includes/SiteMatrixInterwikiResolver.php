<?php

namespace CirrusSearch;

use \ObjectCache;
use \SiteMatrix;
use MediaWiki\MediaWikiServices;

/**
 * InterwikiResolver suited for WMF context and uses SiteMatrix.
 */

class SiteMatrixInterwikiResolver extends BaseInterwikiResolver {
	const MATRIX_CACHE_TTL = 600;

	private $cache;

	/**
	 * @param SearchConfig $config
	 * @param \MultiHttpClient $client http client to fetch cirrus config
	 */
	public function __construct( SearchConfig $config, \MultiHttpClient $client = null ) {
		parent::__construct( $config, $client );
		$this->cache = ObjectCache::getLocalClusterInstance();
		if ( $config->getWikiId() !== wfWikiID() ) {
			throw new \RuntimeException( "This resolver cannot with an external wiki config. (config: " . $config->getWikiId() . ", global: " . wfWikiID() );
		}
		if ( !class_exists( SiteMatrix::class ) ) {
			throw new \RuntimeException( "SiteMatrix is required" );
		}
		if ( !$this->config->has( 'SiteMatrixSites' ) ) {
			throw new \RuntimeException( '$wgSiteMatrixSites must be set.' );
		}
	}

	/**
	 * @param $config SearchConfig
	 * @return bool true if this resolver can run with the specified config
	 */
	public static function accepts( SearchConfig $config ) {
		if ( $config->getWikiId() !== wfWikiID() ) {
			return false;
		}
		if ( !class_exists( SiteMatrix::class ) ) {
			return false;
		}
		if ( !$config->has( 'SiteMatrixSites' ) ) {
			return false;
		}
		return true;
	}

	protected function loadMatrix() {
		$cacheKey = $this->cache->makeKey( 'cirrussearch-interwiki-matrix', 'v1' );
		$matrix = $this->cache->getWithSetCallback(
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

			$matrix = new \SiteMatrix;
			$iwLookup = MediaWikiServices::getInstance()->getInterwikiLookup();
			$wikiDBname = $this->config->get( 'DBname' );
			list( , $myLang ) = $wgConf->siteFromDB( $wikiDBname );
			$siteConf = $this->config->get( 'SiteMatrixSites' );
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
					continue;
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

				if ( !in_array( $prefix, $this->config->get( 'CirrusSearchCrossProjectSearchBlackList' ) ) ) {
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
				$iw = $iwLookup->fetch( $lang );
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
				$wikiLangCode = $wgConf->get( 'wgLanguageCode', $dbname, $myProject, [ 'lang' => $lang, 'site' => $myProject ] );
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
