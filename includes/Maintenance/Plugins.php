<?php

namespace CirrusSearch\Maintenance;

class Plugins {
	// map from elasticsearch plugin name to opensearch
	// plugin name.
	private const ALIASES = [
		'analysis-stconvert' => 'opensearch-analysis-stconvert',
		'experimental-highlighter' => 'cirrus-highlighter',
		'extra' => 'opensearch-extra',
		'extra-analysis-esperanto' => 'opensearch-extra-analysis-esperanto',
		'extra-analysis-homoglyph' => 'opensearch-extra-analysis-homoglyph',
		'extra-analysis-khmer' => 'opensearch-extra-analysis-khmer',
		'extra-analysis-serbian' => 'opensearch-extra-analysis-serbian',
		'extra-analysis-slovak' => 'opensearch-extra-analysis-slovak',
		'extra-analysis-textify' => 'opensearch-extra-analysis-textify',
		'extra-analysis-turkish' => 'opensearch-extra-analysis-turkish',
		'extra-analysis-ukrainian' => 'opensearch-extra-analysis-ukrainian',
	];

	/**
	 * @param string $plugin The name of the elasticsearch plugin to look for
	 * @param string[] $available The set of installed plugins
	 * @return bool True when the plugin is available
	 */
	public static function contains( $plugin, $available ) {
		if ( in_array( $plugin, $available ) ) {
			return true;
		}
		if ( isset( self::ALIASES[$plugin] ) &&
			in_array( self::ALIASES[$plugin], $available )
		) {
			return true;
		}

		return false;
	}
}
