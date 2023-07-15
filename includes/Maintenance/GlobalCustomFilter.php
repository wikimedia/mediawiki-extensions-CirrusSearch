<?php

namespace CirrusSearch\Maintenance;

class GlobalCustomFilter {
	/** @var string filter type, probably 'filter' or 'char_filter'; 'filter' by default */
	private $type;

	/** @var string[] plugins that must be present to use the filter */
	private $requiredPlugins = [];

	/** @var string[] filters this one must come after. see T268730 */
	private $mustFollowFilters = [];

	/** @var string[] languages where this filter should not be used, by language codes */
	private $denyList = [];

	/** @var string[] which analyzers to apply to; 'text' and 'text_search' by default */
	private $applyToAnalyzers = [ 'text', 'text_search' ];

	public function __construct( string $type = 'filter' ) {
		$this->type = $type;
	}

	/**
	 * @param string[] $requiredPlugins
	 * @return self
	 */
	public function setRequiredPlugins( array $requiredPlugins ): self {
		$this->requiredPlugins = $requiredPlugins;
		return $this;
	}

	/**
	 * @param string[] $mustFollowFilters
	 * @return self
	 */
	public function setMustFollowFilters( array $mustFollowFilters ): self {
		$this->mustFollowFilters = $mustFollowFilters;
		return $this;
	}

	/**
	 * @param string[] $denyList
	 * @return self
	 */
	public function setDenyList( array $denyList ): self {
		$this->denyList = $denyList;
		return $this;
	}

	/**
	 * @param string[] $applyToAnalyzers
	 * @return self
	 */
	public function setApplyToAnalyzers( array $applyToAnalyzers ): self {
		$this->applyToAnalyzers = $applyToAnalyzers;
		return $this;
	}

	public function getApplyToAnalyzers() {
		return $this->applyToAnalyzers;
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

	/**
	 * insert one of the global custom filters into the right spot in the analysis chain
	 * @param mixed[] $config the analysis config we are modifying
	 * @param string $analyzer the specifc analyzer we are modifying
	 * @param string $filterName filter to add
	 * @param GlobalCustomFilter $filterInfo includes filter type & incompatible filters
	 * @return mixed[] updated config
	 */
	public static function insertGlobalCustomFilter( array $config, string $analyzer,
		string $filterName, GlobalCustomFilter $filterInfo ) {
		if ( !array_key_exists( $analyzer, $config['analyzer'] ) ) {
			return $config;
		}

		if ( $config['analyzer'][$analyzer]['type'] == 'custom' ) {
			$filters = $config['analyzer'][$analyzer][$filterInfo->type] ?? [];

			$lastMustFollow = -1;
			foreach ( $filterInfo->mustFollowFilters as $mustFollow ) {
				$mustFollowIdx = array_keys( $filters, $mustFollow );
				$mustFollowIdx = end( $mustFollowIdx );
				if ( $mustFollowIdx !== false && $mustFollowIdx > $lastMustFollow ) {
					$lastMustFollow = $mustFollowIdx;
				}
			}
			array_splice( $filters, $lastMustFollow + 1, 0, $filterName );

			$config['analyzer'][$analyzer][$filterInfo->type] = $filters;
		}
		return $config;
	}

}
