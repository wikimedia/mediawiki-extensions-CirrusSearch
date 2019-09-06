<?php

namespace CirrusSearch\Search;

use CirrusSearch\Search\Fetch\HighlightingTrait;
use MWTimestamp;
use Title;

class FullTextCirrusSearchResultBuilder {
	use HighlightingTrait;

	/** @var CirrusSearchResultBuilder|null */
	private $builder;

	/** @var TitleHelper */
	private $titleHelper;

	/**
	 * @param TitleHelper|null $titleHelper
	 */
	public function __construct( TitleHelper $titleHelper = null ) {
		$this->titleHelper = $titleHelper ?: new TitleHelper();
	}

	/**
	 * @param Title $title
	 * @param string $docId
	 * @return CirrusSearchResultBuilder
	 */
	private function newBuilder( Title $title, $docId ): CirrusSearchResultBuilder {
		if ( $this->builder === null ) {
			$this->builder = new CirrusSearchResultBuilder( $title, $docId );
		} else {
			$this->builder->reset( $title, $docId );
		}
		return $this->builder;
	}

	/**
	 * @param \Elastica\Result $result
	 * @return CirrusSearchResult
	 */
	public function build( \Elastica\Result $result ): CirrusSearchResult {
		$title = $this->getTitleHelper()->makeTitle( $result );
		$fields = $result->getFields();
		$builder = $this->newBuilder( $title, $result->getId() )
			->wordCount( $fields['text.word_count'][0] ?? 0 )
			->byteSize( $result->text_bytes ?? 0 )
			->timestamp( new MWTimestamp( $result->timestamp ) )
			->score( $result->getScore() )
			->explanation( $result->getExplanation() );

		if ( isset( $result->namespace_text ) ) {
			$builder->interwikiNamespaceText( $result->namespace_text );
		}

		$highlights = $result->getHighlights();
				// Evil hax to not special case .plain fields for intitle regex
		foreach ( [ 'title', 'redirect.title' ] as $field ) {
			if ( isset( $highlights["$field.plain"] ) && !isset( $highlights[$field] ) ) {
				$highlights[$field] = $highlights["$field.plain"];
				unset( $highlights["$field.plain"] );
			}
		}

		if ( isset( $highlights[ 'title' ] ) ) {
			$nstext = $title->getNamespace() === 0 ? '' :
				$this->titleHelper->getNamespaceText( $title ) . ':';
			$builder->titleSnippet( $nstext . $this->escapeHighlightedText( $highlights[ 'title' ][ 0 ] ) );
		} elseif ( $title->isExternal() ) {
			// Interwiki searches are weird. They won't have title highlights by design, but
			// if we don't return a title snippet we'll get weird display results.
			$builder->titleSnippet( $title->getText() );
		}

		if ( !isset( $highlights[ 'title' ] ) && isset( $highlights[ 'redirect.title' ] ) ) {
			// Make sure to find the redirect title before escaping because escaping breaks it....
			$redirTitle = $this->findRedirectTitle( $result, $highlights[ 'redirect.title' ][ 0 ] );
			if ( $redirTitle !== null ) {
				$builder->redirectTitle( $redirTitle )
					->redirectSnippet( $this->escapeHighlightedText( $highlights[ 'redirect.title' ][ 0 ] ) );
			}
		}

		// This can get skipped if there the page was sent to Elasticsearch without text.
		// This could be a bug or it could be that the page simply doesn't have any text.
		// Prefer source_text.plain it's likely a regex
		// TODO: use the priority system from the FetchPhaseConfigBuilder
		$textHLFields = [ 'source_text.plain', 'text', 'auxiliary_text', 'file_text' ];
		$hasTextSnippet = false;
		foreach ( $textHLFields as $hlField ) {
			if ( isset( $highlights[ $hlField ] ) ) {
				$snippet = $highlights[$hlField][ 0 ];
				if ( $this->containsMatches( $snippet ) ) {
					$builder->textSnippet( $this->escapeHighlightedText( $snippet ) )
						->fileMatch( $hlField === 'file_text' );
					$hasTextSnippet = true;
					break;
				}
			}
		}

		if ( !$hasTextSnippet && isset( $highlights['text'][0] ) ) {
			$builder->textSnippet( $this->escapeHighlightedText( $highlights['text'][0] ) );
		}

		if ( isset( $highlights[ 'heading' ] ) ) {
			$builder->sectionSnippet( $this->escapeHighlightedText( $highlights[ 'heading' ][ 0 ] ) )
				->sectionTitle( $this->findSectionTitle( $highlights[ 'heading' ][ 0 ], $title ) );
		}

		if ( isset( $highlights[ 'category' ] ) ) {
			$builder->categorySnippet( $this->escapeHighlightedText( $highlights[ 'category' ][ 0 ] ) );
		}
		return $builder->build();
	}

	/**
	 * @return TitleHelper
	 */
	protected function getTitleHelper(): TitleHelper {
		return $this->titleHelper;
	}
}
