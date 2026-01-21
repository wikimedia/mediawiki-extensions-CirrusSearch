<?php

namespace CirrusSearch\Search;

use CirrusSearch\Search\Fetch\HighlightingTrait;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;

class SemanticSearchResultBuilder {
	use HighlightingTrait;

	/** @var CirrusSearchResultBuilder|null */
	private $builder;

	/** @var TitleHelper */
	private $titleHelper;

	private string $innerHitsField;
	private string $snippetField;
	private string $sectionField;

	/** @var string[] */
	private $extraFields;

	/**
	 * @param TitleHelper $titleHelper
	 * @param string $innerHitsField The nested field that contains vectors
	 * @param string $snippetField The source field within the nested field to use as a snippet
	 * @param string $sectionField The source field within the nested field to use as section link anchor
	 * @param string[] $extraFields list of extra fields to extract from the source doc
	 */
	public function __construct(
		TitleHelper $titleHelper,
		string $innerHitsField,
		string $snippetField,
		string $sectionField,
		array $extraFields = []
	) {
		$this->titleHelper = $titleHelper;
		$this->innerHitsField = $innerHitsField;
		$this->snippetField = $snippetField;
		$this->sectionField = $sectionField;
		$this->extraFields = $extraFields;
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

		$this->doInnerHits( $title, $result->getParam( 'inner_hits' ) );

		$source = $result->getData();
		foreach ( $this->extraFields as $field ) {
			if ( isset( $source[$field] ) ) {
				$builder->addExtraField( $field, $source[$field] );
			}
		}

		return $builder->build();
	}

	protected function getTitleHelper(): TitleHelper {
		return $this->titleHelper;
	}

	private function doInnerHits( Title $title, array $innerHits ): void {
		foreach ( $innerHits[$this->innerHitsField]['hits']['hits'] as $hit ) {
			if ( isset( $hit['_source'][$this->snippetField] ) ) {
				$this->builder->textSnippet( $hit['_source'][$this->snippetField] );
			}
			if ( isset( $hit['_source'][$this->sectionField] ) ) {
				$this->builder->sectionTitle( $title->createFragmentTarget( $this->titleHelper->sanitizeSectionFragment(
					$hit['_source'][$this->sectionField] ) ) );
			}
			return;
		}
	}

}
