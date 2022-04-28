<?php

namespace CirrusSearch\Hooks;

use CirrusSearch\CirrusSearch;
use Config;
use MediaWiki\MediaWikiServices;
use NullIndexField;
use SearchEngine;
use SearchIndexField;

/**
 * Hooks to allow custom fields to be added to the search index for pages
 */
class CustomPageFields {
	public const CONFIG_OPTION = 'CirrusSearchCustomPageFields';

	/**
	 * Add configured fields to mapping
	 * @param array &$fields array of field definitions to update
	 * @param SearchEngine $engine the search engine requesting field definitions
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SearchIndexFields
	 */
	public static function onSearchIndexFields( array &$fields, SearchEngine $engine ) {
		if ( !( $engine instanceof CirrusSearch ) ) {
			return;
		}
		// += will not overwrite existing fields, only new fields may be added
		$fields += self::buildSearchIndexFields( $engine,
			MediaWikiServices::getInstance()->getMainConfig() );
	}

	/**
	 * Build configured fields
	 * @param SearchEngine $engine
	 * @param Config $config the wiki configuration
	 * @return SearchIndexField[]
	 */
	public static function buildSearchIndexFields(
		SearchEngine $engine,
		Config $config
	): array {
		$fields = [];
		foreach ( $config->get( self::CONFIG_OPTION ) as $name => $type ) {
			$field = $engine->makeSearchFieldMapping( $name, $type );
			if ( $field instanceof NullIndexField ) {
				   throw new \RuntimeException( "Search field $name has invalid type of $type " );
			}
			$fields[$name] = $field;
		}
		return $fields;
	}
}
