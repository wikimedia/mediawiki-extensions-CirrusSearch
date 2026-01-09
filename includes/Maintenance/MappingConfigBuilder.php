<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\Search\CirrusIndexField;
use CirrusSearch\Search\CirrusSearchIndexFieldFactory;
use CirrusSearch\Search\SourceTextIndexField;
use CirrusSearch\Search\TextIndexField;
use CirrusSearch\SearchConfig;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use SearchIndexField;

/**
 * Builds search mapping configuration arrays.
 *
 * @license GPL-2.0-or-later
 */
class MappingConfigBuilder {
	// Bit field parameters for buildConfig
	public const PREFIX_START_WITH_ANY = 1;
	public const PHRASE_SUGGEST_USE_TEXT = 2;
	public const OPTIMIZE_FOR_EXPERIMENTAL_HIGHLIGHTER = 4;

	/**
	 * Version number for the core analysis. Increment the major
	 * version when the analysis changes in an incompatible way,
	 * and change the minor version when it changes but isn't
	 * incompatible
	 */
	public const VERSION = '1.10';

	/**
	 * @var bool should the index be optimized for the experimental highlighter?
	 */
	private $optimizeForExperimentalHighlighter;

	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var CirrusSearch
	 */
	protected $engine;

	/**
	 * @var CirrusSearchIndexFieldFactory
	 */
	protected $searchIndexFieldFactory;

	/**
	 * @var int
	 */
	protected $flags = 0;
	/**
	 * @var CirrusSearchHookRunner
	 */
	private $cirrusSearchHookRunner;

	/** @var bool if the icu plugin is available */
	private bool $icu;
	/**
	 * @var Language the content language
	 */
	private Language $language;

	/**
	 * @param bool $optimizeForExperimentalHighlighter should the index be optimized for the experimental highlighter?
	 * @param array $plugins list of installed plugins
	 * @param int $flags
	 * @param SearchConfig|null $config
	 * @param CirrusSearchHookRunner|null $cirrusSearchHookRunner
	 * @param Language|null $language
	 */
	public function __construct(
		bool $optimizeForExperimentalHighlighter,
		array $plugins,
		int $flags = 0,
		?SearchConfig $config = null,
		?CirrusSearchHookRunner $cirrusSearchHookRunner = null,
		?Language $language = null
	) {
		$this->optimizeForExperimentalHighlighter = $optimizeForExperimentalHighlighter;
		if ( $this->optimizeForExperimentalHighlighter ) {
			$flags |= self::OPTIMIZE_FOR_EXPERIMENTAL_HIGHLIGHTER;
		}
		$this->flags = $flags;
		$this->icu = Plugins::contains( 'analysis-icu', $plugins );
		$this->engine = new CirrusSearch( $config );
		$this->config = $this->engine->getConfig();
		$this->searchIndexFieldFactory = new CirrusSearchIndexFieldFactory( $this->config );
		$this->cirrusSearchHookRunner = $cirrusSearchHookRunner ?? new CirrusSearchHookRunner(
			MediaWikiServices::getInstance()->getHookContainer() );
		$this->language = $language ?? MediaWikiServices::getInstance()->getContentLanguage();

		$this->validatePlugins( $plugins );
	}

	private function validatePlugins( array $plugins ) {
		if ( $this->config->get( 'CirrusSearchOptimizeForExperimentalHighlighter' ) &&
			!Plugins::contains( 'experimental-highlighter', $plugins )
		) {
			throw new \InvalidArgumentException(
				"wgCirrusSearchOptimizeIndexForExperimentalHighlighter is set to true but the " .
				"'experimental-highlighter' plugin is not available."
			);
		}

		if ( $this->config->getElement( 'CirrusSearchNaturalTitleSort', 'build' ) && !$this->icu ) {
			throw new \InvalidArgumentException(
				"wgCirrusSearchNaturalTitleSort is set to build but the 'analysis-icu' plugin " .
				"is not available."
			);
		}
	}

	/**
	 * Get definitions for default index fields.
	 * These fields are always present in the index.
	 * @return array
	 */
	private function getDefaultFields() {
		// Note never to set something as type='object' here because that isn't returned
		// by the search engine and is inferred anyway.
		$titleExtraAnalyzers = [
			[ 'analyzer' => 'prefix', 'search_analyzer' => 'near_match', 'index_options' => 'docs', 'norms' => false ],
			[
				'analyzer' => 'prefix_asciifolding',
				'search_analyzer' => 'near_match_asciifolding',
				'index_options' => 'docs',
				'norms' => false
			],
			[ 'analyzer' => 'near_match', 'index_options' => 'docs', 'norms' => false ],
			[ 'analyzer' => 'near_match_asciifolding', 'index_options' => 'docs', 'norms' => false ],
			[ 'type' => 'keyword', 'normalizer' => 'keyword' ],
		];
		if ( $this->flags & self::PREFIX_START_WITH_ANY ) {
			$titleExtraAnalyzers[] = [
				'analyzer' => 'word_prefix',
				'search_analyzer' => 'plain_search',
				'index_options' => 'docs'
			];
		}
		if ( $this->icu && $this->config->getElement( 'CirrusSearchNaturalTitleSort', 'build' ) ) {
			$titleExtraAnalyzers[] = [
				'fieldName' => 'natural_sort',
				'type' => 'icu_collation_keyword',
				'ignore_above' => AnalysisConfigBuilder::KEYWORD_IGNORE_ABOVE,
				// doc values only
				'index' => false,
				'numeric' => true,
				'strength' => 'tertiary',
				// icu_collation_keyword will use new ULocale(String $l) if only provided the language
				// which supports BCP 47 language code.
				'language' => $this->language->toBcp47Code()
			];
		}

		$suggestField = [
			'type' => 'text',
			'similarity' => TextIndexField::getSimilarity( $this->config, 'suggest' ),
			'index_options' => 'freqs',
			'analyzer' => 'suggest',
		];

		if ( $this->config->getElement( 'CirrusSearchPhraseSuggestReverseField', 'build' ) ) {
			$suggestField['fields'] = [
				'reverse' => [
					'type' => 'text',
					'similarity' => TextIndexField::getSimilarity( $this->config, 'suggest', 'reverse' ),
					'index_options' => 'freqs',
					'analyzer' => 'suggest_reverse',
				],
			];
		}

		$page = [
			'dynamic' => false,
			'properties' => [
				'timestamp' => [
					'type' => 'date',
					'format' => 'dateOptionalTime',
				],
				'create_timestamp' => [
					'type' => 'date',
					'format' => 'dateOptionalTime',
				],
				'page_id' => [
					'type' => 'long',
					'index' => false,
				],
				'wiki' => $this->searchIndexFieldFactory
					->newKeywordField( 'wiki' )
					->getMapping( $this->engine ),
				'namespace' => $this->searchIndexFieldFactory
					->newLongField( 'namespace' )
					->getMapping( $this->engine ),
				'namespace_text' => $this->searchIndexFieldFactory
					->newKeywordField( 'namespace_text' )
					->withDocValues()
					->getMapping( $this->engine ),
				'title' => $this->searchIndexFieldFactory
					->newStringField( 'title',
						TextIndexField::ENABLE_NORMS
						| TextIndexField::COPY_TO_SUGGEST
						| TextIndexField::COPY_TO_SUGGEST_VARIANT
						| TextIndexField::SUPPORT_REGEX,
						$titleExtraAnalyzers )
					->setMappingFlags( $this->flags )
					->getMapping( $this->engine ),
				'text' => $this->getTextFieldMapping(),
				'text_bytes' => $this->searchIndexFieldFactory
					->newLongField( 'text_bytes' )
					->getMapping( $this->engine ),
				'source_text' => $this->buildSourceTextStringField( 'source_text' )
					->setMappingFlags( $this->flags )->getMapping( $this->engine ),
				'redirect' => [
					'dynamic' => false,
					'properties' => [
						'namespace' => $this->searchIndexFieldFactory
							->newLongField( 'namespace' )
							->getMapping( $this->engine ),
						'title' => $this->searchIndexFieldFactory
							->newStringField( 'redirect.title', TextIndexField::ENABLE_NORMS
								| TextIndexField::SPEED_UP_HIGHLIGHTING
								| TextIndexField::COPY_TO_SUGGEST
								| TextIndexField::COPY_TO_SUGGEST_VARIANT
								| TextIndexField::SUPPORT_REGEX,
								$titleExtraAnalyzers
							)
							->setMappingFlags( $this->flags )
							->getMapping( $this->engine ),
					]
				],
				'incoming_links' => $this->searchIndexFieldFactory
					->newLongField( 'incoming_links' )
					->getMapping( $this->engine ),
				'local_sites_with_dupe' => $this->searchIndexFieldFactory
					->newKeywordField( 'local_sites_with_dupe' )
					->setFlag( SearchIndexField::FLAG_CASEFOLD )
					->getMapping( $this->engine ),
				'suggest' => $suggestField,
			]
		];

		if ( $this->config->get( 'CirrusSearchPhraseSuggestBuildVariant' ) ) {
			$page['properties']['suggest_variant'] = $suggestField;
		}

		return $page;
	}

	/**
	 * Build the mapping config.
	 * @return array the mapping config
	 */
	public function buildConfig() {
		global $wgCirrusSearchWeights;

		$page = $this->getDefaultFields();

		$fields = $this->engine->getSearchIndexFields();

		foreach ( $fields as $fieldName => $field ) {
			if ( $field instanceof CirrusIndexField ) {
				$field->setMappingFlags( $this->flags );
			}
			$config = $field->getMapping( $this->engine );
			if ( $config ) {
				$page['properties'][$fieldName] = $config;
			}
		}

		// Unclear how this would otherwise fit into the process to construct the mapping.
		// Not used directly in cirrus, supports queries from 'add-a-link' (T301096).
		if ( isset( $page['properties']['outgoing_link'] ) ) {
			$page['properties']['outgoing_link']['fields']['token_count'] = [
				'type' => 'token_count',
				'analyzer' => 'keyword',
			];
		}

		// Now layer all the fields into the all field once per weight.  Querying it isn't strictly the
		// same as querying each field - in some ways it is better!  In others it is worse....

		// Better because theoretically tf/idf based scoring works better this way.
		// Worse because we have to analyze each field multiple times....  Bleh!
		// This field can't be used for the fvh/experimental highlighter for several reasons:
		// 1. It is built with copy_to and not stored.
		// 2. The term frequency information is all whoppy compared to the "real" source text.
		$allField = $this->searchIndexFieldFactory->
			newStringField( 'all', TextIndexField::ENABLE_NORMS );
		$page['properties']['all'] =
			$allField->setMappingFlags( $this->flags )->getMapping( $this->engine );
		$page = $this->setupCopyTo( $page, $wgCirrusSearchWeights, 'all' );

		// Now repeat for near_match fields.  The same considerations above apply except near_match
		// is never used in phrase queries or highlighting.
		$page[ 'properties' ][ 'all_near_match' ] = [
			'type' => 'text',
			'analyzer' => 'near_match',
			'index_options' => 'freqs',
			'norms' => false,
			'similarity' => TextIndexField::getSimilarity( $this->config, 'all_near_match' ),
			'fields' => [
				'asciifolding' => [
					'type' => 'text',
					'analyzer' => 'near_match_asciifolding',
					'index_options' => 'freqs',
					'norms' => false,
					'similarity' => TextIndexField::getSimilarity( $this->config, 'all_near_match', 'asciifolding' ),
				],
			],
		];
		$nearMatchFields = [
			'title' => $wgCirrusSearchWeights[ 'title' ],
			'redirect' => $wgCirrusSearchWeights[ 'redirect' ],
		];
		return $this->setupCopyTo( $page, $nearMatchFields, 'all_near_match' );
	}

	/**
	 * Setup copy_to for some fields to $destination.
	 * @param array $config to modify
	 * @param array $fields field name to number of times copied
	 * @param string $destination destination of the copy
	 * @return array $config modified with the copy_to setup
	 */
	private function setupCopyTo( $config, $fields, $destination ) {
		foreach ( $fields as $field => $weight ) {
			// Note that weights this causes weights that are not whole numbers to be rounded up.
			// We're ok with that because we don't have a choice.
			for ( $r = 0; $r < $weight; $r++ ) {
				if ( $field === 'redirect' ) {
					// Redirect is in a funky place
					$config[ 'properties' ][ 'redirect' ][ 'properties' ][ 'title' ][ 'copy_to' ][] = $destination;
				} else {
					$config[ 'properties' ][ $field ][ 'copy_to' ][] = $destination;
				}
			}
		}

		return $config;
	}

	/**
	 * Build the source_text index field
	 *
	 * @param string $fieldName usually "source_text"
	 * @return SourceTextIndexField
	 */
	protected function buildSourceTextStringField( $fieldName ) {
		return new SourceTextIndexField( $fieldName, SearchIndexField::INDEX_TYPE_TEXT, $this->config );
	}

	/**
	 * @return array
	 */
	private function getTextFieldMapping() {
		$stringFieldMapping = $this->searchIndexFieldFactory->newStringField(
			'text',
			null,
			[]
		)->setMappingFlags( $this->flags )->getMapping( $this->engine );

		$extraFieldMapping = [
			'fields' => [
				'word_count' => [
					'type' => 'token_count',
					'analyzer' => 'plain',
				]
			]
		];

		$textFieldMapping = array_merge_recursive( $stringFieldMapping, $extraFieldMapping );

		return $textFieldMapping;
	}

	/**
	 * Whether or not it's safe to optimize the analysis config.
	 * It's generally safe to optimize if all the analyzers needed are
	 * properly referenced in the mapping.
	 * In the case an analyzer is used directly in a query but not referenced
	 * in the mapping it's not safe to optimize.
	 *
	 * @return bool
	 */
	public function canOptimizeAnalysisConfig() {
		return true;
	}
}
