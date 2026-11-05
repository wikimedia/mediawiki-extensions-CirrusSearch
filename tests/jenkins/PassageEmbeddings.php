<?php

namespace CirrusSearch\Jenkins;

use CirrusSearch\CirrusSearch;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\WikiTextStructure;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Search\SearchEngine;
use MediaWiki\Search\SearchIndexField;
use MediaWiki\Search\SearchIndexFieldDefinition;

/**
 * Passage vectors for the semantic integration tests.
 *
 * Prod reads them from a dedicated semantic index. The test env has one cluster, so they
 * go on the primary documents of the regular indices. The field names are the ones the
 * semantic builder profiles expect. Every page gets them, so the lexical suite runs with
 * them too.
 *
 * @license GPL-2.0-or-later
 */
class PassageEmbeddings {
	private const FIELD = 'passage_chunk_embedding';
	private const PASSAGE_WORDS = 100;

	/**
	 * @param SearchIndexField[] &$fields
	 * @param SearchEngine $engine
	 */
	public static function onSearchIndexFields( &$fields, $engine ) {
		if ( !( $engine instanceof CirrusSearch ) ) {
			return;
		}
		$fields[self::FIELD] = new class( self::FIELD, SearchIndexField::INDEX_TYPE_NESTED )
			extends SearchIndexFieldDefinition
		{
			/** @inheritDoc */
			public function getMapping( SearchEngine $engine ) {
				return [
					'type' => 'nested',
					'properties' => [
						'section' => [ 'type' => 'text', 'index' => false ],
						'text' => [ 'type' => 'text', 'index' => false ],
						'knn' => [ 'type' => 'knn_vector', 'dimension' => FakeInference::DIMENSIONS ],
					],
				];
			}
		};
	}

	/**
	 * @param array &$fields
	 * @param ContentHandler $handler
	 * @param WikiPage $page
	 * @param ParserOutput $output
	 * @param SearchEngine $engine
	 * @param RevisionRecord $revision
	 */
	public static function onSearchDataForIndex2(
		array &$fields,
		ContentHandler $handler,
		WikiPage $page,
		ParserOutput $output,
		SearchEngine $engine,
		RevisionRecord $revision
	) {
		if ( !( $engine instanceof CirrusSearch ) ) {
			return;
		}
		// The text field still holds the raw source here, the plain text is set after
		// this hook runs.
		$text = $output->hasText() ? ( new WikiTextStructure( $output ) )->getMainText() : '';
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$section = $page->getTitle()->getText();
		$passages = [];
		foreach ( array_chunk( $words, self::PASSAGE_WORDS ) as $chunk ) {
			$passage = implode( ' ', $chunk );
			$passages[] = [
				'section' => $section,
				'text' => $passage,
				'knn' => FakeInference::embed( $passage ),
			];
		}
		// Always set, so an edit that removes all text also clears the old passages.
		$fields[self::FIELD] = $passages;
	}
}
