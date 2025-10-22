<?php

namespace CirrusSearch\Maintenance;

use MediaWiki\Json\FormatJson;

/**
 * Filter unused and duplicate entries from search index configuration
 */
class AnalysisFilter {
	/** @var string[] List of key's in mappings that reference analyzers */
	private static $ANALYZER_FIELDS = [ 'analyzer', 'search_analyzer', 'search_quote_analyzer' ];

	/** @var string[] List of key's in mappings that reference analyzers */
	private static $NORMALIZER_FIELDS = [ 'normalizer' ];

	/** @var string[] List of key's in mappings that must be recursively searched */
	private static $SUBFIELD_FIELDS = [ 'fields', 'properties' ];

	/**
	 * Recursively finds used analysis components from search mappings
	 *
	 * @param array $properties a 'properties' or 'fields' list from the mappings
	 * @param string[] $fieldsToLookAt inspect these mapping properties
	 * @return Set The set of referenced analyzers
	 */
	private function findUsedFromField( array $properties, array $fieldsToLookAt ) {
		$analyzers = new Set();
		foreach ( $properties as $name => $config ) {
			foreach ( $fieldsToLookAt as $key ) {
				if ( isset( $config[$key] ) ) {
					$analyzers->add( $config[$key] );
				}
			}
			foreach ( self::$SUBFIELD_FIELDS as $key ) {
				if ( isset( $config[$key] ) ) {
					$analyzers->union( $this->findUsedFromField( $config[$key], $fieldsToLookAt ) );
				}
			}
		}
		return $analyzers;
	}

	/**
	 * @param array[] $mappings search mapping configuration
	 * @param string[] $fieldsToLookAt inspect these mapping properties
	 * @return Set The set of analyzer names referenced in $mappings
	 */
	public function findUsedAnalysisComponentsInMappings( array $mappings, array $fieldsToLookAt ): Set {
		$analyzers = new Set();
		if ( isset( $mappings['properties'] ) ) {
			// modern search, no index types
			$analyzers->union(
				$this->findUsedFromField( $mappings['properties'], $fieldsToLookAt ) );
		} else {
			// BC for parts still using index types
			foreach ( $mappings as $config ) {
				$analyzers->union(
					$this->findUsedFromField( $config['properties'], $fieldsToLookAt ) );
			}
		}
		return $analyzers;
	}

	public function findUsedAnalyzersInMappings( array $mappings ): Set {
		return $this->findUsedAnalysisComponentsInMappings( $mappings, self::$ANALYZER_FIELDS );
	}

	public function findUsedNormalizersInMappings( array $mappings ): Set {
		return $this->findUsedAnalysisComponentsInMappings( $mappings, self::$NORMALIZER_FIELDS );
	}

	/**
	 * Recursively applies analyzer aliases to search mappings
	 *
	 * @param array $properties a 'properties' or 'fields' list from the mappings
	 * @param string[] $aliases Map from current analyzer name to replacement name
	 * @param string[] $fieldsToLookAt inspect these mapping properties
	 * @return array $properties with analyzer aliases applied
	 */
	private function pushAnalysisComponentAliasesIntoField( array $properties, array $aliases, array $fieldsToLookAt ): array {
		foreach ( $properties as &$config ) {
			foreach ( $fieldsToLookAt as $key ) {
				if ( isset( $config[$key] ) && isset( $aliases[$config[$key]] ) ) {
					$config[$key] = $aliases[$config[$key]];
				}
			}
			foreach ( self::$SUBFIELD_FIELDS as $key ) {
				if ( isset( $config[$key] ) && is_array( $config[$key] ) ) {
					$config[$key] = $this->pushAnalysisComponentAliasesIntoField(
						$config[$key], $aliases, $fieldsToLookAt
					);
				}
			}
		}
		return $properties;
	}

	/**
	 * @param array[] $mappings search index mapping configuration
	 * @param array<string, string> $aliases Mapping from old name to new name for analyzers
	 * @return array Updated index mapping configuration
	 */
	public function pushAnalyzerAliasesIntoMappings( array $mappings, array $aliases ): array {
		return $this->pushAnalysisComponentAliasesIntoMappings( $mappings, $aliases, self::$ANALYZER_FIELDS );
	}

	/**
	 * @param array[] $mappings search index mapping configuration
	 * @param array<string, string> $aliases Mapping from old name to new name for analyzers
	 * @return array Updated index mapping configuration
	 */
	public function pushNormalizerAliasesIntoMappings( array $mappings, array $aliases ): array {
		return $this->pushAnalysisComponentAliasesIntoMappings( $mappings, $aliases, self::$NORMALIZER_FIELDS );
	}

	/**
	 * @param array[] $mappings search index mapping configuration
	 * @param array<string, string> $aliases Mapping from old name to new name for analyzers
	 * @param string[] $fieldsToLookAt inspect these mapping properties
	 * @return array Updated index mapping configuration
	 */
	private function pushAnalysisComponentAliasesIntoMappings( array $mappings, array $aliases, array $fieldsToLookAt ): array {
		if ( isset( $mappings['properties'] ) ) {
			// modern search, no index types
			$mappings['properties'] = $this->pushAnalysisComponentAliasesIntoField(
				$mappings['properties'], $aliases, $fieldsToLookAt
			);
		} else {
			// BC for parts still using index types
			foreach ( $mappings as $mappingType => $config ) {
				$mappings[$mappingType]['properties'] = $this->pushAnalysisComponentAliasesIntoField(
					$config['properties'], $aliases, $fieldsToLookAt
				);
			}
		}
		return $mappings;
	}

	private function filter( array $data, Set $keysToKeep ): array {
		foreach ( $data as $k => $v ) {
			if ( !$keysToKeep->contains( $k ) ) {
				unset( $data[$k] );
			}
		}
		return $data;
	}

	/**
	 * @param array $analysis The index.analysis field of the search index settings
	 * @param Set $usedAnalyzers Set of analyzers to keep configurations for
	 * @param Set $usedNormalizers Set of normalizers to keep configurations for
	 * @return array The $analysis array filtered to only pieces needed for $usedAnalyzers
	 */
	public function filterUnusedAnalysisChain( $analysis, Set $usedAnalyzers, Set $usedNormalizers ) {
		$sets = [
			'analyzer' => $usedAnalyzers,
			'normalizer' => $usedNormalizers,
			'filter' => new Set(),
			'char_filter' => new Set(),
			'tokenizer' => new Set(),
		];
		foreach ( $analysis['analyzer'] ?? [] as $name => $config ) {
			if ( !$usedAnalyzers->contains( $name ) ) {
				continue;
			}
			foreach ( [ 'filter', 'char_filter' ] as $k ) {
				if ( isset( $config[$k] ) ) {
					$sets[$k]->addAll( $config[$k] );
				}
			}
			if ( isset( $config['tokenizer'] ) ) {
				$sets['tokenizer']->add( $config['tokenizer'] );
			}
		}

		foreach ( $analysis['normalizer'] ?? [] as $name => $config ) {
			if ( !$usedNormalizers->contains( $name ) ) {
				continue;
			}
			foreach ( [ 'filter', 'char_filter' ] as $k ) {
				if ( isset( $config[$k] ) ) {
					$sets[$k]->addAll( $config[$k] );
				}
			}
		}

		foreach ( $sets as $k => $used ) {
			if ( isset( $analysis[$k] ) ) {
				$analysis[$k] = $this->filter( $analysis[$k], $used );
			}
		}

		return $analysis;
	}

	private function recursiveKsort( array $array ): array {
		foreach ( $array as $k => $v ) {
			if ( is_array( $v ) ) {
				$array[$k] = $this->recursiveKsort( $v );
			}
		}
		ksort( $array );
		return $array;
	}

	private function calcDeduplicationAliases( array $input ): array {
		$keysByContent = [];
		foreach ( $input as $k => $v ) {
			$sorted = $this->recursiveKsort( $v );
			$content = FormatJson::encode( $sorted );
			$keysByContent[$content][] = $k;
		}
		$aliases = [];
		foreach ( $keysByContent as $keys ) {
			// Min to give a stable winner for each group.
			$winner = count( $keys ) === 1 ? reset( $keys ) : min( ...$keys );
			foreach ( $keys as $key ) {
				$aliases[$key] = $winner;
			}
		}
		return $aliases;
	}

	/**
	 * Remove duplicate analysis chain elements and report aliases that need
	 * to be applied to mapping configuration.
	 *
	 * This is necessary for indices such as wikibase that eagerly create
	 * analysis chains for many languages. Quite a few languages result in the
	 * same elements and this deduplication can remove a large fraction of the
	 * configuration.
	 *
	 * @param array $analysis The index.analysis field of the search index settings
	 * @return array<string[]> list of (analyzer, normalizer) map from old component name to new name.
	 */
	public function deduplicateAnalysisConfig( array $analysis ): array {
		// Deduplicate children first to normalize analyzer configuration.
		foreach ( [ 'tokenizer', 'filter', 'char_filter' ] as $k ) {
			if ( !isset( $analysis[$k] ) ) {
				continue;
			}
			$aliases = $this->calcDeduplicationAliases( $analysis[$k] );
			$analysis[$k] = $this->filter( $analysis[$k], new Set( $aliases ) );

			foreach ( [ 'analyzer', 'normalizer' ] as $component ) {
				// Push deduplications into analyzers/normalizers that reference them
				if ( !isset( $analysis[$component] ) ) {
					continue;
				}
				foreach ( $analysis[$component] as $name => $analyzerConfig ) {
					if ( !isset( $analyzerConfig[$k] ) ) {
						continue;
					}
					if ( is_array( $analyzerConfig[$k] ) ) {
						// filter, char_filter
						foreach ( $analyzerConfig[$k] as $i => $value ) {
							// TODO: in theory, all values should be set already?
							if ( isset( $aliases[$value] ) ) {
								$analysis[$component][$name][$k][$i] = $aliases[$value];
							}
						}
					} elseif ( isset( $aliases[$analyzerConfig[$k]] ) ) {
						// tokenizer
						$analysis[$component][$name][$k] = $aliases[$analyzerConfig[$k]];
					}
				}
			}
		}

		// Once the analyzer/normalizer configuration has been normalized by deduplication
		// we can figure out which of the analyzers are duplicates as well.
		return [
			$this->calcDeduplicationAliases( $analysis['analyzer'] ?? [] ),
			$this->calcDeduplicationAliases( $analysis['normalizer'] ?? [] ),
		];
	}

	/**
	 * Shrink the size of the search index configuration
	 *
	 * Removes analysis chain elements that are defined but never referenced
	 * from the mappings. Optionally deduplicates elements of the analysis
	 * chain.
	 *
	 * @param array $analysis search index analysis configuration
	 * @param array $mappings search index mapping configuration
	 * @param bool $deduplicate When true deduplicate the analysis chain
	 * @param string[] $protectedAnalyzers list of named analyzers that should not be removed.
	 * @param string[] $protectedNormalizers list of named normalizers that should not be removed.
	 * @return array [$settings, $mappings]
	 */
	public function filterAnalysis(
		array $analysis,
		array $mappings,
		bool $deduplicate = false,
		array $protectedAnalyzers = [],
		array $protectedNormalizers = []
	) {
		if ( $deduplicate ) {
			[ $analyzerAliases, $normalizerAliases ] = $this->deduplicateAnalysisConfig( $analysis );
			$mappings = $this->pushAnalyzerAliasesIntoMappings( $mappings, $analyzerAliases );
			$mappings = $this->pushNormalizerAliasesIntoMappings( $mappings, $normalizerAliases );
		}
		$usedAnalyzers = $this->findUsedAnalyzersInMappings( $mappings );
		$usedNormalizers = $this->findUsedNormalizersInMappings( $mappings );
		// protected analyzers may be renamed in the mappings, but this retains them in the config as well
		// to ensure they are available for query-time.
		$usedAnalyzers->addAll( $protectedAnalyzers );
		$usedNormalizers->addAll( $protectedNormalizers );
		$analysis = $this->filterUnusedAnalysisChain( $analysis, $usedAnalyzers, $usedNormalizers );
		return [ $analysis, $mappings ];
	}
}
