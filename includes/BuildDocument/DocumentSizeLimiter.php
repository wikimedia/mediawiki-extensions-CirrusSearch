<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Search\CirrusIndexField;
use Elastica\Document;
use Elastica\JSON;

/**
 * An approximate, incomplete and rather dangerous algorithm to reduce the size of a CirrusSearch
 * document.
 *
 * This class is meant to reduce the size of abnormally large documents. What we can consider
 * abnormally large is certainly prone to interpretation but this class was designed with numbers
 * like 1Mb considered as extremely large. You should not expect this class to be byte precise
 * and there is no guarantee that the resulting size after the operation will be below the expected
 * max. There might be various reasons for this:
 * - there are other fields than the ones listed above that take a lot of space
 * - the expected size is so low that it does not even allow the json overhead to be present
 *
 * If the use-case is to ensure that the resulting json representation is below a size S you should
 * definitely account for some overhead and ask this class to reduce the document to something smaller
 * than S (i.e. S*0.9).
 *
 * Limiter heuristics are controlled by a profile that supports the following criteria:
 * - max_size (int): the target maximum size of the document (when serialized as json)
 * - field_types (array<string, string>): field name as key, the type of field (text or keyword) as value
 * - max_field_size (array<string, int>): field name as key, max size as value, truncate these fields
 * to the appropriate size
 * - fields (array<string, int>): field name as key, min size as value, truncate these fields up to this
 * minimal size as long as the document size is above max_size
 * - markup_template (string): mark the document with this template if it was oversize.
 *
 * Text fields are truncated using mb_strcut, if the string is part of an array and it becomes empty
 * after the truncation it's removed from the array, if the string is a "keyword" (non tokenized
 * field) it's not truncated and simply removed from its array.
 *
 * If an array is mixing string and non-string data it's ignored.
 */
class DocumentSizeLimiter {
	public const MANDATORY_REDUCTION_BUCKET = "mandatory_reduction";
	public const OVERSIZE_REDUCTION_REDUCTION_BUCKET = "oversize_reduction";
	public const HINT_DOC_SIZE_LIMITER_STATS = 'DocumentSizeLimiter_stats';

	/** @var int */
	private $maxDocSize;
	/** @var int */
	private $docLength;
	/** @var Document */
	private $document;
	/** @var string[] */
	private $fieldTypes;
	/** @var int[] list of max field length */
	private $maxFieldSize;
	/** @var int[] list of fields to truncate when the doc is oversize, value is the min length to keep */
	private $fields;
	/** @var array<string,array<string,int>> */
	private $stats;
	/** @var mixed|null */
	private $markupTemplate;
	/** @var int the actual max size a truncated document can (takes into account the markup template that has to be added) */
	private $actualMaxDocSize;

	public function __construct( array $profile ) {
		$this->maxDocSize = $profile['max_size'] ?? PHP_INT_MAX;
		$this->fieldTypes = $profile['field_types'] ?? [];
		$this->maxFieldSize = $profile["max_field_size"] ?? [];
		$this->fields = $profile["fields"] ?? [];
		$this->markupTemplate = $profile["markup_template"] ?? null;
		$this->actualMaxDocSize = $this->maxDocSize;
		if ( $this->markupTemplate !== null ) {
			$this->actualMaxDocSize -= strlen( $this->markupTemplate ) + 3; // 3 is 2 " and a comma
		}
	}

	public static function estimateDataSize( Document $document ): int {
		try {
			return strlen( JSON::stringify( $document->getData(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE ) );
		} catch ( \JsonException $je ) {
			// Ignore, consider this of length 0, process is likely to fail at later point
		}
		return 0;
	}

	/**
	 * Truncate some textual data from the input Document.
	 * @param Document $document
	 * @return array some statistics about the process.
	 */
	public function resize( Document $document ): array {
		$this->stats = [];
		$this->document = $document;
		$originalDocLength = self::estimateDataSize( $document );
		$this->docLength = $originalDocLength;
		// first pass to force some fields
		foreach ( $this->maxFieldSize as $field => $len ) {
			$this->truncateField( $field, ( $this->fieldTypes[$field] ?? "text" ) === "keyword",
				$len, 0, self::MANDATORY_REDUCTION_BUCKET );
		}

		// second pass applied only if the doc is oversize
		if ( $this->docLength > $this->maxDocSize ) {
			foreach ( $this->fields as $field => $len ) {
				if ( $this->docLength <= $this->actualMaxDocSize ) {
					break;
				}
				$this->truncateField( $field, ( $this->fieldTypes[$field] ?? "text" ) === "keyword",
					$len, $this->actualMaxDocSize, self::OVERSIZE_REDUCTION_REDUCTION_BUCKET );
			}
		}
		/** @phan-suppress-next-line PhanRedundantCondition */
		if ( $this->markupTemplate != null && !empty( $this->stats[self::OVERSIZE_REDUCTION_REDUCTION_BUCKET] ) ) {
			$this->markWithTemplate( $document );
		}
		$this->stats["document"] = [
			"original_length" => $originalDocLength,
			"new_length" => $this->docLength,
		];
		CirrusIndexField::setHint( $document, self::HINT_DOC_SIZE_LIMITER_STATS, $this->stats );
		return $this->stats;
	}

	private function truncateField( string $field, bool $keyword, int $minFieldLength, int $maxDocSize, string $statBucket ): void {
		if ( !$this->document->has( $field ) ) {
			return;
		}
		$fieldData = $this->document->get( $field );
		$plainString = false;

		// If the field is a plain string but is marked as a keyword we prefer to not touch it.
		// It is probable that such fields are not of variable length (IDs, mimetypes) and thus
		// it would make little to have a profile that tries to truncate those. But out of caution
		// we simply skip those.
		if ( is_string( $fieldData ) && !$keyword ) {
			// wrap and plain string into an array to reuse the same loop as string[] fields.
			$fieldData = [ $fieldData ];
			$plainString = true;
		}
		if ( !is_array( $fieldData ) ) {
			return;
		}

		$onlyStrings = array_reduce( $fieldData, static function ( $isString, $str ) {
			return $isString && is_string( $str );
		}, true );

		$onlyStrings = array_reduce( $fieldData, static function ( $isString, $str ) {
			return $isString && is_string( $str );
		}, true );
		if ( !$onlyStrings ) {
			// not messing-up with mixed-types
			return;
		}

		$fieldLen = array_reduce( $fieldData, static function ( $siz, $str ) {
			return $siz + strlen( $str );
		}, 0 );
		$sizeReduction = 0;
		// Since we generally truncate the end of a text we also remove array elements from the end.
		for ( $index = count( $fieldData ) - 1; $index >= 0; $index-- ) {
			$remainingFieldLen = $fieldLen - $sizeReduction;
			$maxSizeToRemove = $this->docLength - $sizeReduction - $maxDocSize;
			if ( $remainingFieldLen <= $minFieldLength ) {
				break;
			}
			if ( $maxSizeToRemove <= 0 ) {
				break;
			}
			if ( $remainingFieldLen <= 0 ) {
				break;
			}
			$data = &$fieldData[$index];
			$len = strlen( $data );
			if ( $keyword ) {
				$sizeReduction += strlen( $data );
				unset( $fieldData[$index] );
			} else {
				$removableLen = $remainingFieldLen - $minFieldLength;

				$newLen = $len - max( min( $maxSizeToRemove, $len, $removableLen ), 0 );
				$data = mb_strcut( $data, 0, $newLen );
				$sizeReduction += $len - strlen( $data );
				if ( $data === "" ) {
					unset( $fieldData[$index] );
				}
			}
		}
		$this->docLength -= $sizeReduction;
		$fieldData = array_values( $fieldData );
		if ( $plainString ) {
			$fieldData = array_pop( $fieldData ) ?? ""; // prefers empty string over null
		}
		$this->document->set( $field, $fieldData );
		$this->stats[$statBucket][$field] = $sizeReduction;
	}

	private function markWithTemplate( Document $document ) {
		$templates = [];
		if ( $document->has( "template" ) ) {
			$templates = $document->get( "template" );
		}
		// add this markup to the main NS to avoid pulling Title and the ns text service
		// this will be searchable via hastemplate::the_markup_template
		$templates[] = $this->markupTemplate;
		$this->docLength += strlen( $this->markupTemplate ) + 2 + ( count( $templates ) > 1 ? 1 : 0 );
		$document->set( "template", $templates );
	}
}
