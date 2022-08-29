<?php

namespace CirrusSearch\Maintenance;

class GlobalCustomFilter {
	/** @var string filter type, probably 'filter' or 'char_filter'; 'filter' by default */
	public $type;

	/** @var string[] plugins that must be present to use the filter */
	public $requiredPlugins;

	/** @var string[] filters this one must come after. see T268730 */
	public $incompatibleFilters;

	/** @var string[] languages where this filter should not be used, by language codes */
	public $denyList;

	/** @var string[] which analyzers to apply to; 'text' and 'text_search' by default */
	public $applyTo;

	public function __construct(
		string $type = 'filter',
		array $requiredPlugins = [],
		array $incompatibleFilters = [],
		array $applyTo = [ 'text', 'text_search' ],
		array $denyList = []
	) {
		$this->type = $type;
		$this->requiredPlugins = $requiredPlugins;
		$this->denyList = $denyList;
		$this->incompatibleFilters = $incompatibleFilters;
		$this->applyTo = $applyTo;
	}

	/**
	 * check to see if the filter is compatible with a given language and set of
	 * installed plugins
	 *
	 * @param string $language
	 * @param string[] $installedPlugins
	 * @return bool
	 */
	public function filterIsUsable( string $language, array $installedPlugins ): bool {
		if ( in_array( $language, $this->denyList ) ) {
			return false;
		}
		foreach ( $this->requiredPlugins as $reqPlugin ) {
			if ( !in_array( $reqPlugin, $installedPlugins ) ) {
				return false;
			}
		}
		return true;
	}
}
