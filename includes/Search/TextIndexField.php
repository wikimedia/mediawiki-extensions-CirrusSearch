<?php
namespace CirrusSearch\Search;

use CirrusSearch\Maintenance\MappingConfigBuilder;
use SearchIndexField;
use CirrusSearch\SearchConfig;
use SearchEngine;

/**
 * Index field representing keyword.
 * Keywords use special analyzer.
 * @package CirrusSearch
 */
class TextIndexField extends CirrusIndexField {
	/**
	 * Distance that lucene places between multiple values of the same field.
	 * Set pretty high to prevent accidental phrase queries between those values.
	 */
	const POSITION_INCREMENT_GAP = 10;

	/* Bit field parameters for string fields.
     *   ENABLE_NORMS: Enable norms on the field.  Good for text you search against but useless
     *     for fields that don't get involved in the score.
	 *   COPY_TO_SUGGEST: Copy the contents of this field to the suggest field for "Did you mean".
	 *   SPEED_UP_HIGHLIGHTING: Store extra data in the field to speed up highlighting.  This is important for long
	 *     strings or fields with many values.
	 */
	const ENABLE_NORMS = 0x1000000;
	// FIXME: when exactly we want to disable norms for text fields?
	const COPY_TO_SUGGEST = 0x2000000;
	const SPEED_UP_HIGHLIGHTING = 0x4000000;
	const STRING_FIELD_MASK = 0xFFFFFF;

	/**
	 * Extra definitions.
	 * @var array
	 */
	protected $extra;
	/**
	 * Text options for this field
	 * @var int
	 */
	private $textOptions;

	/**
	 * Name of the type in Elastic
	 * @var string
	 */
	protected $typeName = 'string';

	public function __construct( $name, $type, SearchConfig $config, $extra = [] ) {
		parent::__construct($name, $type, $config );

		$this->extra = $extra;
	}

	/**
	 * Set text options for this field if non-default
	 * @param $options
	 * @return $this
	 */
	public function setTextOptions( $options ) {
		$this->textOptions = $options;
		return $this;
	}

	/**
	 * Get text options for this field
	 * @param $mappingFlags
	 * @return int
	 */
	protected function getTextOptions( $mappingFlags ) {
		if ( !is_null( $this->textOptions ) ) {
			return $this->textOptions;
		}
		$options = self::ENABLE_NORMS | self::SPEED_UP_HIGHLIGHTING;
		if ( $mappingFlags & MappingConfigBuilder::PHRASE_SUGGEST_USE_TEXT &&
		     !$this->checkFlag( SearchIndexField::FLAG_SCORING )
		) {
			// SCORING fields are not copied since this info is already in other fields
			$options |= self::COPY_TO_SUGGEST;
		}
		if ( $this->checkFlag( SearchIndexField::FLAG_NO_HIGHLIGHT ) ) {
			// Disable highlighting is asked to
			$options &= ~self::SPEED_UP_HIGHLIGHTING;
		}
		return $options;
	}

	/**
	 * @param SearchEngine $engine
	 * @return array|void
	 */
	public function getMapping( SearchEngine $engine ) {
		if (!($engine instanceof \CirrusSearch)) {
			throw new \LogicException("Cannot map CirrusSearch fields for another engine.");
		}
		/**
		 * @var \CirrusSearch $engine
		 */
		$this->flags =
			( $this->flags & self::STRING_FIELD_MASK ) | $this->getTextOptions( $this->mappingFlags );

		$field = parent::getMapping( $engine );

		if ( $this->checkFlag( self::COPY_TO_SUGGEST ) ) {
			$field[ 'copy_to' ] = [ 'suggest' ];
		}

		if ( $this->checkFlag( self::FLAG_NO_INDEX ) ) {
			// no need to configure further a not-indexed field
			return $field;
		}

		$extra = $this->extra;
		if ( $this->mappingFlags & MappingConfigBuilder::PREFIX_START_WITH_ANY ) {
			$extra[] = [
				'analyzer' => 'word_prefix',
				'search_analyzer' => 'plain_search',
				'index_options' => 'docs'
			];
		}
		if ( $this->checkFlag( SearchIndexField::FLAG_CASEFOLD ) ) {
			$extra[] = [
				'analyzer' => 'lowercase_keyword',
				'norms' => [ 'enabled' => false ],
				'index_options' => 'docs',
				'ignore_above' => KeywordIndexField::KEYWORD_IGNORE_ABOVE,
			];
		}

		// multi_field is dead in 1.0 so we do this which actually looks less gnarly.
		$field += [
			'analyzer' => 'text',
			'search_analyzer' => 'text_search',
			'position_increment_gap' => self::POSITION_INCREMENT_GAP,
			'similarity' => self::getSimilarity( $this->config, $this->name ),
			'fields' => [
				'plain' => [
					'type' => 'string',
					'analyzer' => 'plain',
					'search_analyzer' => 'plain_search',
					'position_increment_gap' => self::POSITION_INCREMENT_GAP,
					'similarity' => self::getSimilarity( $this->config, $this->name, 'plain' ),
				],
			]
		];
		$disableNorms = !$this->checkFlag( self::ENABLE_NORMS );
		if ( $disableNorms ) {
			$disableNorms = [ 'norms' => [ 'enabled' => false ] ];
			$field = array_merge( $field, $disableNorms );
			$field[ 'fields' ][ 'plain' ] = array_merge( $field[ 'fields' ][ 'plain' ], $disableNorms );
		}
		foreach ( $extra as $extraField ) {
			$extraName = $extraField[ 'analyzer' ];

			$field[ 'fields' ][ $extraName ] = array_merge( [
				'similarity' => self::getSimilarity( $this->config, $this->name, $extraName ),
				'type' => 'string',
				'position_increment_gap' => self::POSITION_INCREMENT_GAP,
			], $extraField );
			if ( $disableNorms ) {
				$field[ 'fields' ][ $extraName ] = array_merge(
					$field[ 'fields' ][ $extraName ], $disableNorms );
			}
		}
		if ( $this->mappingFlags & MappingConfigBuilder::OPTIMIZE_FOR_EXPERIMENTAL_HIGHLIGHTER ) {
			if ( $this->checkFlag( self::SPEED_UP_HIGHLIGHTING ) ) {
				$field[ 'index_options' ] = 'offsets';
				$fieldNames = [ 'plain', 'prefix', 'prefix_asciifolding', 'near_match', 'near_match_asciifolding' ];
				foreach ( $fieldNames as $fieldName ) {
					if ( isset( $field[ 'fields' ][ $fieldName ] ) ) {
						$field[ 'fields' ][ $fieldName ][ 'index_options' ] = 'offsets';
					}
				}
			}
		} else {
			// We use the FVH on all fields so turn on term vectors
			$field[ 'term_vector' ] = 'with_positions_offsets';
			$fieldNames = [ 'plain', 'prefix', 'prefix_asciifolding', 'near_match', 'near_match_asciifolding' ];
			foreach ( $fieldNames as $fieldName ) {
				if ( isset( $field[ 'fields' ][ $fieldName ] ) ) {
					$field[ 'fields' ][ $fieldName ][ 'term_vector' ] = 'with_positions_offsets';
				}
			}
		}
		return $field;
	}

	/**
	 * Get the field similarity
	 * @param SearchConfig $config
	 * @param string $field
	 * @param string $analyzer
	 * @return string
	 */
	public static function getSimilarity( SearchConfig $config, $field, $analyzer = null ) {
		$similarity = $config->get( 'CirrusSearchSimilarityProfile' );
		$fieldSimilarity = 'default';
		if ( isset( $similarity['fields'] ) ) {
			if( isset( $similarity['fields'][$field] ) ) {
				$fieldSimilarity = $similarity['fields'][$field];
			} else if ( $similarity['fields']['__default__'] ) {
				$fieldSimilarity = $similarity['fields']['__default__'];
			}

			if ( $analyzer != null && isset( $similarity['fields']["$field.$analyzer"] ) ) {
				$fieldSimilarity = $similarity['fields']["$field.$analyzer"];
			}
		}
		return $fieldSimilarity;
	}
}
