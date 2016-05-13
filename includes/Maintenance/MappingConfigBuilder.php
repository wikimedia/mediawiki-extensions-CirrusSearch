<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Search\CirrusIndexField;
use CirrusSearch\Search\IntegerIndexField;
use CirrusSearch\Search\KeywordIndexField;
use CirrusSearch\SearchConfig;
use CirrusSearch\Search\TextIndexField;
use Hooks;
use MediaWiki\MediaWikiServices;
use SearchIndexField;

/**
 * Builds elasticsearch mapping configuration arrays.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class MappingConfigBuilder {
	// Bit field parameters for buildConfig
	const PREFIX_START_WITH_ANY = 1;
	const PHRASE_SUGGEST_USE_TEXT = 2;
	const OPTIMIZE_FOR_EXPERIMENTAL_HIGHLIGHTER = 4;

	/**
	 * Version number for the core analysis. Increment the major
	 * version when the analysis changes in an incompatible way,
	 * and change the minor version when it changes but isn't
	 * incompatible
	 */
	const VERSION = '1.9';

	/**
	 * @var bool should the index be optimized for the experimental highlighter?
	 */
	private $optimizeForExperimentalHighlighter;

	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var \CirrusSearch
	 */
	private $engine;

	/**
	 * Constructor
	 * @param bool $optimizeForExperimentalHighlighter should the index be optimized for the experimental highlighter?
	 * @param SearchConfig $config
	 */
	public function __construct( $optimizeForExperimentalHighlighter, SearchConfig $config = null ) {
		$this->optimizeForExperimentalHighlighter = $optimizeForExperimentalHighlighter;
		if ( is_null( $config ) ) {
			$config =
				MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
		}
		$this->config = $config;
		$this->engine = new \CirrusSearch();
		$this->engine->setConfig( $config );
	}

	/**
	 * Get definitions for default index fields.
	 * These fields are always present in the index.
	 * @param int $flags
	 * @return array
	 */
	private function getDefaultFields( $flags ) {
		global $wgCirrusSearchWikimediaExtraPlugin;

		// Note never to set something as type='object' here because that isn't returned by elasticsearch
		// and is inferred anyway.
		$titleExtraAnalyzers = array(
			array( 'analyzer' => 'suggest' ),
			array( 'analyzer' => 'prefix', 'search_analyzer' => 'near_match', 'index_options' => 'docs', 'norms' => array( 'enabled' => false ) ),
			array( 'analyzer' => 'prefix_asciifolding', 'search_analyzer' => 'near_match_asciifolding', 'index_options' => 'docs', 'norms' => array( 'enabled' => false ) ),
			array( 'analyzer' => 'near_match', 'index_options' => 'docs', 'norms' => array( 'enabled' => false ) ),
			array( 'analyzer' => 'near_match_asciifolding', 'index_options' => 'docs', 'norms' => array( 'enabled' => false ) ),
			array( 'analyzer' => 'keyword', 'index_options' => 'docs', 'norms' => array( 'enabled' => false ) ),
		);
		if ( $flags & self::PREFIX_START_WITH_ANY ) {
			$titleExtraAnalyzers[] = array(
				'analyzer' => 'word_prefix',
				'search_analyzer' => 'plain_search',
				'index_options' => 'docs'
			);
		}

		$sourceExtraAnalyzers = array();
		if ( isset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] ) &&
		     in_array( 'build', $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] ) ) {
			$sourceExtraAnalyzers[] = array(
				'analyzer' => 'trigram',
				'index_options' => 'docs',
			);
		}

		$page = [
			'dynamic' => false,
			'_all' => array( 'enabled' => false ),
			'properties' => array(
				'timestamp' => array(
					'type' => 'date',
					'format' => 'dateOptionalTime',
				),
				'wiki' => $this->buildKeywordField( 'wiki' )->getMapping( $this->engine ),
				'namespace' => $this->buildLongField( 'namespace' )->getMapping( $this->engine ),
				'namespace_text' => $this->buildKeywordField( 'namespace_text' )
					->getMapping( $this->engine ),
				'title' => $this->buildStringField( 'title',
					TextIndexField::ENABLE_NORMS | TextIndexField::COPY_TO_SUGGEST,
					$titleExtraAnalyzers )->setMappingFlags( $flags )->getMapping( $this->engine ),
				'text' => array_merge_recursive( $this->buildStringField( 'text', null,
					( $flags & self::PHRASE_SUGGEST_USE_TEXT ) ? [ 'analyzer' => 'suggest' ] : [ ] )
					->setMappingFlags( $flags )->getMapping( $this->engine ), array(
						'fields' => array(
							'word_count' => array(
								'type' => 'token_count',
								'store' => true,
								'analyzer' => 'plain',
							)
						)
					) ),
				'text_bytes' => $this->buildLongField( 'text_bytes' )
					->setFlag( SearchIndexField::FLAG_NO_INDEX )
					->getMapping( $this->engine ),
				'source_text' => $this->buildStringField( 'source_text', 0,
					$sourceExtraAnalyzers
				)->setMappingFlags( $flags )->getMapping( $this->engine ),
				'redirect' => array(
					'dynamic' => false,
					'properties' => array(
						'namespace' => $this->buildLongField( 'namespace' )
							->getMapping( $this->engine ),
						'title' => $this->buildStringField( 'redirect.title',
							TextIndexField::ENABLE_NORMS | TextIndexField::SPEED_UP_HIGHLIGHTING |
							TextIndexField::COPY_TO_SUGGEST, $titleExtraAnalyzers )
							->setMappingFlags( $flags )
							->getMapping( $this->engine ),
					)
				),
				'incoming_links' => $this->buildLongField( 'incoming_links' )
					->getMapping( $this->engine ),
				'local_sites_with_dupe' => $this->buildKeywordField( 'local_sites_with_dupe' )
					->setFlag( SearchIndexField::FLAG_CASEFOLD )
					->getMapping( $this->engine ),
				'suggest' => array(
					'type' => 'string',
					'analyzer' => 'suggest',
				),
				// FIXME: this should be moved to Wikibase Client
				'wikibase_item' => $this->buildKeywordField( 'wikibase_item' )
					->getMapping( $this->engine ),
			)
		];

		return $page;
	}

	/**
	 * Build the mapping config.
	 * @param int $flags Flags for building the configuration
	 * @return array the mapping config
	 */
	public function buildConfig( $flags = 0 ) {
		global $wgCirrusSearchAllFields,
		              $wgCirrusSearchWeights;

		if ( $this->optimizeForExperimentalHighlighter ) {
			$flags |= self::OPTIMIZE_FOR_EXPERIMENTAL_HIGHLIGHTER;
		}
		$page = $this->getDefaultFields( $flags );

		$fields = $this->engine->getSearchIndexFields();

		foreach ( $fields as $fieldName => $field ) {
			if ( $field instanceof CirrusIndexField ) {
				$field->setMappingFlags( $flags );
			}
			$config = $field->getMapping( $this->engine );
			if ( $config ) {
				$page['properties'][$fieldName] = $config;
			}
		}

		if ( $wgCirrusSearchAllFields[ 'build' ] ) {
			// Now layer all the fields into the all field once per weight.  Querying it isn't strictly the
			// same as querying each field - in some ways it is better!  In others it is worse....

			// Better because theoretically tf/idf based scoring works better this way.
			// Worse because we have to analyze each field multiple times....  Bleh!
			// This field can't be used for the fvh/experimental highlighter for several reasons:
			//  1. It is built with copy_to and not stored.
			//  2. The term frequency information is all whoppy compared to the "real" source text.
			$allField = $this->buildStringField( 'all', TextIndexField::ENABLE_NORMS );
			$page['properties']['all'] =
				$allField->setMappingFlags( $flags )->getMapping( $this->engine );
			$page = $this->setupCopyTo( $page, $wgCirrusSearchWeights, 'all' );

			// Now repeat for near_match fields.  The same considerations above apply except near_match
			// is never used in phrase queries or highlighting.
			$page[ 'properties' ][ 'all_near_match' ] = array(
				'type' => 'string',
				'analyzer' => 'near_match',
				'index_options' => 'freqs',
				'position_increment_gap' => TextIndexField::POSITION_INCREMENT_GAP,
				'norms' => array( 'enabled' => false ),
				'similarity' => $allField->getSimilarity( 'all_near_match' ),
				'fields' => array(
					'asciifolding' => array(
						'type' => 'string',
						'analyzer' => 'near_match_asciifolding',
						'index_options' => 'freqs',
						'position_increment_gap' => TextIndexField::POSITION_INCREMENT_GAP,
						'norms' => array( 'enabled' => false ),
						'similarity' => $allField->getSimilarity( 'all_near_match', 'asciifolding' ),
					),
				),
			);
			$nearMatchFields = array(
				'title' => $wgCirrusSearchWeights[ 'title' ],
				'redirect' => $wgCirrusSearchWeights[ 'redirect' ],
			);
			$page = $this->setupCopyTo( $page, $nearMatchFields, 'all_near_match' );
		}

		$config[ 'page' ] = $page;

		$config[ 'namespace' ] = array(
			'dynamic' => false,
			'_all' => array( 'enabled' => false ),
			'properties' => array(
				'name' => array(
					'type' => 'string',
					'analyzer' => 'near_match_asciifolding',
					'norms' => array( 'enabled' => false ),
					'index_options' => 'docs',
					'ignore_above' => KeywordIndexField::KEYWORD_IGNORE_ABOVE,
				),
			),
		);

		Hooks::run( 'CirrusSearchMappingConfig', array( &$config, $this ) );

		return $config;
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
	 * Build a string field that does standard analysis for the language.
	 * @param string $fieldName the field name
	 * @param int $options Field options:
	 *   ENABLE_NORMS: Enable norms on the field.  Good for text you search against but bad for array fields and useless
	 *     for fields that don't get involved in the score.
	 *   COPY_TO_SUGGEST: Copy the contents of this field to the suggest field for "Did you mean".
	 *   SPEED_UP_HIGHLIGHTING: Store extra data in the field to speed up highlighting.  This is important for long
	 *     strings or fields with many values.
	 * @param array $extra Extra analyzers for this field beyond the basic text and plain.
	 * @return TextIndexField definition of the field
	 */
	public function buildStringField( $fieldName, $options = null, $extra = [] ) {
		$field =
			new TextIndexField( $fieldName, SearchIndexField::INDEX_TYPE_TEXT, $this->config,
				$extra );
		$field->setTextOptions( $options );
		return $field;
	}

	/**
	 * Create a long field.
	 * @param string $name Field name
	 * @return IntegerIndexField
	 */
	public function buildLongField( $name ) {
		return new IntegerIndexField( $name, SearchIndexField::INDEX_TYPE_INTEGER, $this->config );
	}

	/**
	 * Create a long field.
	 * @param string $name Field name
	 * @return KeywordIndexField
	 */
	public function buildKeywordField( $name ) {
		return new KeywordIndexField( $name, SearchIndexField::INDEX_TYPE_KEYWORD, $this->config );
	}
}

