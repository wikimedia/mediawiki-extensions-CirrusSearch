<?php

namespace CirrusSearch\Maintenance;

class GlobalCustomFilter {
	/** @var string filter type, probably 'filter' or 'char_filter'; 'filter' by default */
	private $type;

	/** @var string[] languages where this filter should not be used, by language codes */
	private $languageDenyList = [];

	/** @var string[] only languages where this filter should be used, by language codes */
	private $languageAllowList = [];

	/** @var string[] plugins that must be present to use the filter */
	private $requiredPlugins = [];

	/** @var string local filter to use instead if requiredPlugins are not available */
	private $fallbackFilter = '';

	/** @var string[] which analyzers to apply to; 'text' and 'text_search' by default */
	private $applyToAnalyzers = [ 'text', 'text_search' ];

	/** @var string tokenizer that must be present to use the filter */
	private $requiredTokenizer = '';

	/** @var string[] token filters with which the filter is not allowed/needed */
	private $disallowedTokenFilters = [];

	/** @var string[] character filters with which the filter is not allowed/needed */
	private $disallowedCharFilters = [];

	/** @var string[] filters this one must come after. see T268730 */
	private $mustFollowFilters = [];

	public function __construct( string $type = 'filter' ) {
		$this->type = $type;
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
	 * @param string[] $languageAllowList
	 * @return self
	 */
	public function setLanguageAllowList( array $languageAllowList ): self {
		$this->languageAllowList = $languageAllowList;
		return $this;
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
	 * @param string $fallbackFilter
	 * @return self
	 */
	public function setFallbackFilter( string $fallbackFilter ): self {
		$this->fallbackFilter = $fallbackFilter;
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
	 * @param string $requiredTokenizer
	 * @return self
	 */
	public function setRequiredTokenizer( string $requiredTokenizer ): self {
		$this->requiredTokenizer = $requiredTokenizer;
		return $this;
	}

	/**
	 * @param string[] $disallowedTokenFilters
	 * @return self
	 */
	public function setDisallowedTokenFilters( array $disallowedTokenFilters ): self {
		$this->disallowedTokenFilters = $disallowedTokenFilters;
		return $this;
	}

	/**
	 * @param string[] $disallowedCharFilters
	 * @return self
	 */
	public function setDisallowedCharFilters( array $disallowedCharFilters ): self {
		$this->disallowedCharFilters = $disallowedCharFilters;
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
		foreach ( $customFilters as $filterName => $gcfInfo ) {
			if ( !$gcfInfo->languageCheck( $language ) ) {
				continue;
			}

			if ( !$gcfInfo->pluginsAvailable( $installedPlugins ) ) {
				if ( $gcfInfo->fallbackFilter ) {
					$filterName = $gcfInfo->fallbackFilter;
				} else {
					continue;
				}
			}

			foreach ( $gcfInfo->getApplyToAnalyzers() as $analyzer ) {
				if ( $gcfInfo->analyzerCheck( $config, $analyzer, $filterName ) ) {
					$config = $gcfInfo->insertGlobalCustomFilter( $config, $analyzer,
						$filterName );
				}
			}
		}

		return $config;
	}

	/**
	 * check language deny and allow lists to see if this filter is allowed in this
	 * analyzer
	 *
	 * @param string $language
	 * @return bool
	 */
	private function languageCheck( string $language ): bool {
		if ( in_array( $language, $this->languageDenyList )
			 || ( $this->languageAllowList &&
				!in_array( $language, $this->languageAllowList ) )
			) {
			 return false;
		}
		return true;
	}

	/**
	 * check to see if the filter is compatible with the set of installed plugins
	 *
	 * @param string[] $installedPlugins
	 * @return bool
	 */
	private function pluginsAvailable( array $installedPlugins ): bool {
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
	private function requiredTokenizerUsed( array $analyzerConfig ): bool {
		if ( $this->requiredTokenizer ) {
			if ( !array_key_exists( 'tokenizer', $analyzerConfig ) ||
					$analyzerConfig[ 'tokenizer' ] != $this->requiredTokenizer ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * check if any disqualifying token filters are already present
	 *
	 * @param mixed[] $config
	 * @param string $analyzer
	 * @return bool
	 */
	private function disallowedTokenFiltersPresent( array $config, string $analyzer ): bool {
		$filters = $config['analyzer'][$analyzer]['filter'] ?? [];
		foreach ( $this->disallowedTokenFilters as $df ) {
			if ( in_array( $df, $filters ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * check if any disqualifying character filters are already present
	 *
	 * @param mixed[] $config
	 * @param string $analyzer
	 * @return bool
	 */
	private function disallowedCharFiltersPresent( array $config, string $analyzer ): bool {
		$filters = $config['analyzer'][$analyzer]['char_filter'] ?? [];

		foreach ( $this->disallowedCharFilters as $df ) {
			if ( in_array( $df, $filters ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * check that the analyzer checks all the boxes to insert this filter
	 *
	 * @param mixed[] $config
	 * @param string $analyzer
	 * @param string $filterName filter we want to add
	 * @return bool
	 */
	private function analyzerCheck( array $config, string $analyzer,
			string $filterName ): bool {
		$filters = $config['analyzer'][$analyzer][$this->type] ?? [];

		if ( !array_key_exists( $analyzer, $config['analyzer'] ) // array exists
			|| $config['analyzer'][$analyzer]['type'] != 'custom' // array is custom
			|| !$this->requiredTokenizerUsed( $config['analyzer'][$analyzer] )
			|| $this->disallowedTokenFiltersPresent( $config, $analyzer )
			|| $this->disallowedCharFiltersPresent( $config, $analyzer )
			|| in_array( $filterName, $filters ) // not a duplicate
			) {
			return false;
		}

		return true;
	}

	/**
	 * insert one of the global custom filters into the right spot in the analysis chain
	 *
	 * @param mixed[] $config the analysis config we are modifying
	 * @param string $analyzer the specifc analyzer we are modifying
	 * @param string $filterName filter to add
	 * @return mixed[] updated config
	 */
	private function insertGlobalCustomFilter( array $config, string $analyzer,
			string $filterName ) {
		$filters = $config['analyzer'][$analyzer][$this->type] ?? [];

		$lastMustFollow = -1;
		foreach ( $this->mustFollowFilters as $mustFollow ) {
			$mustFollowIdx = array_keys( $filters, $mustFollow );
			$mustFollowIdx = end( $mustFollowIdx );
			if ( $mustFollowIdx !== false && $mustFollowIdx > $lastMustFollow ) {
				$lastMustFollow = $mustFollowIdx;
			}
		}
		array_splice( $filters, $lastMustFollow + 1, 0, $filterName );

		$config['analyzer'][$analyzer][$this->type] = $filters;

		return $config;
	}

}
