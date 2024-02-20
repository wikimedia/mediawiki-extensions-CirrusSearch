<?php

namespace CirrusSearch\Maintenance;

class GlobalCustomFilter {
	/** @var string filter type, probably 'filter' or 'char_filter'; 'filter' by default */
	private $type;

	/** @var string[] plugins that must be present to use the filter */
	private $requiredPlugins = [];

	/** @var string local filter to use instead if requiredPlugins are not available */
	private $fallbackFilter = '';

	/** @var string tokenizer that must be present to use the filter */
	private $requiredTokenizer = '';

	/** @var string[] filters this one must come after. see T268730 */
	private $mustFollowFilters = [];

	/** @var string[] languages where this filter should not be used, by language codes */
	private $languageDenyList = [];

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
	 * @param string $requiredTokenizer
	 * @return self
	 */
	public function setRequiredTokenizer( string $requiredTokenizer ): self {
		$this->requiredTokenizer = $requiredTokenizer;
		return $this;
	}

	/**
	 * @param string $fallbackFilter
	 * @return self
	 */
	public function setFallbackFilter( string $fallbackFilter ): self {
		$this->fallbackFilter = $fallbackFilter;
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
	 * @param string[] $languageDenyList
	 * @return self
	 */
	public function setLanguageDenyList( array $languageDenyList ): self {
		$this->languageDenyList = $languageDenyList;
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
	 * check to see if the filter is compatible with the set of installed plugins
	 *
	 * @param string[] $installedPlugins
	 * @return bool
	 */
	public function pluginsAvailable( array $installedPlugins ): bool {
		foreach ( $this->requiredPlugins as $reqPlugin ) {
			if ( !in_array( $reqPlugin, $installedPlugins ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * check to see if the filter is compatible with the configured tokenizer
	 *
	 * @param mixed[] $analyzerConfig
	 * @return bool
	 */
	public function requiredTokenizerUsed( array $analyzerConfig ): bool {
		if ( $this->requiredTokenizer ) {
			if ( !array_key_exists( 'tokenizer', $analyzerConfig ) ||
					$analyzerConfig[ 'tokenizer' ] != $this->requiredTokenizer ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * update languages with global custom filters (e.g., homoglyph & nnbsp filters)
	 *
	 * @param mixed[] $config
	 * @param string $language
	 * @param GlobalCustomFilter[] $customFilters list of filters and info
	 * @param string[] $installedPlugins
	 * @return mixed[] updated config
	 */
	public static function enableGlobalCustomFilters( array $config, string $language,
			array $customFilters, array $installedPlugins ) {
		foreach ( $customFilters as $gcf => $gcfInfo ) {
			$filterName = $gcf;

			if ( !in_array( $language, $gcfInfo->languageDenyList ) ) {
				$filterIsUsable = $gcfInfo->pluginsAvailable( $installedPlugins );

				if ( !$filterIsUsable && $gcfInfo->fallbackFilter ) {
					$filterName = $gcfInfo->fallbackFilter;
					$filterIsUsable = true;
				}

				if ( $filterIsUsable ) {
					foreach ( $gcfInfo->getApplyToAnalyzers() as $analyzer ) {
						$config = $gcfInfo->insertGlobalCustomFilter( $config, $analyzer,
							$filterName, $gcfInfo );
					}
				}
			}
		}

		return $config;
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

		if ( $config['analyzer'][$analyzer]['type'] == 'custom' &&
				$filterInfo->requiredTokenizerUsed( $config['analyzer'][$analyzer] )
				) {
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
