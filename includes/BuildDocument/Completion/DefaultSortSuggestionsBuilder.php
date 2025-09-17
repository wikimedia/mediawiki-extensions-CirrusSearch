<?php

namespace CirrusSearch\BuildDocument\Completion;

use Transliterator;

/**
 * Extra builder that appends the defaultsort value to suggest and suggest-stop
 * inputs on title suggestions
 */
class DefaultSortSuggestionsBuilder implements ExtraSuggestionsBuilder {
	private const FIELD = 'defaultsort';
	private Transliterator $utr30;

	public function __construct() {
		$utr30 = Transliterator::createFromRules( file_get_contents( __DIR__ . '/../../../data/utr30.txt' ) );
		if ( $utr30 === null ) {
			throw new \RuntimeException( "Failed to construct transliterator" );
		}
		$this->utr30 = $utr30;
	}

	/**
	 * @inheritDoc
	 */
	public function getRequiredFields() {
		return [ self::FIELD ];
	}

	/**
	 * @param mixed[] $inputDoc
	 * @param string $suggestType (title or redirect)
	 * @param int $score
	 * @param \Elastica\Document $suggestDoc suggestion type (title or redirect)
	 * @param int $targetNamespace
	 */
	public function build( array $inputDoc, $suggestType, $score, \Elastica\Document $suggestDoc, $targetNamespace ) {
		if ( $targetNamespace != $inputDoc['namespace'] ) {
			// This is a cross namespace redirect, we don't
			// add defaultsort for this one.
			return;
		}
		if ( $suggestType === SuggestBuilder::TITLE_SUGGESTION && isset( $inputDoc[ self::FIELD ] ) ) {
			$value = $inputDoc[self::FIELD];
			if ( is_string( $value ) && $this->isRelevantDefaultSort( $inputDoc["title"], $value ) ) {
				$this->addInputToFST( $value, 'suggest', $suggestDoc );
				$this->addInputToFST( $value, 'suggest-stop', $suggestDoc );
			}
		}
	}

	/**
	 * @param string $input the new input
	 * @param string $fstField field name
	 * @param \Elastica\Document $suggestDoc
	 */
	private function addInputToFST( $input, $fstField, $suggestDoc ) {
		if ( $suggestDoc->has( $fstField ) ) {
			$entryDef = $suggestDoc->get( $fstField );
			$entryDef['input'][] = $input;
			$suggestDoc->set( $fstField, $entryDef );
		}
	}

	/**
	 * Verify that default is relevant to the title.
	 * We inspect a common pattern for default which is using a comma as a separator:
	 *  John Doe (Person) => Doe, John
	 * @param string $title
	 * @param string $defaultSort
	 * @return bool
	 */
	private function isRelevantDefaultSort( string $title, string $defaultSort ): bool {
		$split = explode( ', ', $defaultSort, 2 );
		if ( count( $split ) !== 2 ) {
			return false;
		}
		$normalizedTitle = $this->utr30->transliterate( $title );
		if ( $normalizedTitle === false ) {
			return false;
		}
		$normalizedDefaultSort = $this->utr30->transliterate( "{$split[1]} {$split[0]}" );
		if ( $normalizedDefaultSort === false ) {
			return false;
		}
		$normalizedDefaultSort = preg_replace( '/\s+/', $normalizedDefaultSort, ' ' );
		$normalizedDefaultSort = preg_replace( '/\s+$/', $normalizedDefaultSort, '' );
		$normalizedTitle = preg_replace( '/\s+/', $normalizedTitle, ' ' );
		$normalizedTitle = preg_replace( '/\s+$/', $normalizedTitle, '' );
		if ( $normalizedDefaultSort !== null && $normalizedTitle !== null ) {
			return str_starts_with( $normalizedTitle, $normalizedDefaultSort );
		}
		return false;
	}
}
