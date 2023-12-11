<?php

namespace CirrusSearch\Search;

use InvalidArgumentException;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Assert\Assert;

/**
 * Helper class to build ArrayCirrusSearchResult instances
 */
class CirrusSearchResultBuilder {
	/**
	 * @var array
	 */
	private $data;

	/**
	 * @var Title
	 */
	private $title;

	/**
	 * @param Title $title
	 * @param string $docId
	 */
	public function __construct( Title $title, $docId ) {
		$this->reset( $title, $docId );
	}

	/**
	 * @return ArrayCirrusSearchResult
	 */
	public function build() {
		return new ArrayCirrusSearchResult( $this->title, $this->data );
	}

	/**
	 * Reset the current builder to reuse its instance.
	 * @param Title $title
	 * @param string $docId
	 * @return self
	 */
	public function reset( Title $title, $docId ): self {
		$this->data = [ ArrayCirrusSearchResult::DOC_ID => $docId ];
		$this->title = $title;
		return $this;
	}

	/**
	 * @param float $score
	 * @return self
	 */
	public function score( $score ): self {
		return $this->setValue( ArrayCirrusSearchResult::SCORE, $score );
	}

	/**
	 * @param array $explanation
	 * @return self
	 */
	public function explanation( array $explanation ): self {
		return $this->setValue( ArrayCirrusSearchResult::EXPLANATION, $explanation );
	}

	/**
	 * @param string $textSnippet
	 * @return self
	 */
	public function textSnippet( $textSnippet ): self {
		return $this->setValue( ArrayCirrusSearchResult::TEXT_SNIPPET, $textSnippet );
	}

	/**
	 * @param string $textSnippetField
	 * @return self
	 */
	public function textSnippetField( $textSnippetField ): self {
		return $this->setValue( ArrayCirrusSearchResult::TEXT_SNIPPET_FIELD, $textSnippetField );
	}

	/**
	 * @param string $titleSnippet
	 * @return self
	 */
	public function titleSnippet( $titleSnippet ): self {
		return $this->setValue( ArrayCirrusSearchResult::TITLE_SNIPPET, $titleSnippet );
	}

	/**
	 * @param string $titleSnippetField
	 * @return self
	 */
	public function titleSnippetField( $titleSnippetField ): self {
		return $this->setValue( ArrayCirrusSearchResult::TITLE_SNIPPET_FIELD, $titleSnippetField );
	}

	/**
	 * @param string $redirectSnippet
	 * @return self
	 */
	public function redirectSnippet( $redirectSnippet ): self {
		return $this->setValue( ArrayCirrusSearchResult::REDIRECT_SNIPPET, $redirectSnippet );
	}

	/**
	 * @param string $redirectSnippetField
	 * @return self
	 */
	public function redirectSnippetField( $redirectSnippetField ): self {
		return $this->setValue( ArrayCirrusSearchResult::REDIRECT_SNIPPET_FIELD, $redirectSnippetField );
	}

	/**
	 * @param string $redirectTitle
	 * @return self
	 */
	public function redirectTitle( $redirectTitle ): self {
		return $this->setValue( ArrayCirrusSearchResult::REDIRECT_TITLE, $redirectTitle );
	}

	/**
	 * @param string $sectionSnippet
	 * @return self
	 */
	public function sectionSnippet( $sectionSnippet ): self {
		return $this->setValue( ArrayCirrusSearchResult::SECTION_SNIPPET, $sectionSnippet );
	}

	/**
	 * @param string $sectionSnippetField
	 * @return self
	 */
	public function sectionSnippetField( $sectionSnippetField ): self {
		return $this->setValue( ArrayCirrusSearchResult::SECTION_SNIPPET_FIELD, $sectionSnippetField );
	}

	/**
	 * @param Title $sectionTitle
	 * @return self
	 */
	public function sectionTitle( Title $sectionTitle ): self {
		return $this->setValue( ArrayCirrusSearchResult::SECTION_TITLE, $sectionTitle );
	}

	/**
	 * @param string $categorySnippet
	 * @return CirrusSearchResultBuilder
	 */
	public function categorySnippet( $categorySnippet ): self {
		return $this->setValue( ArrayCirrusSearchResult::CATEGORY_SNIPPET, $categorySnippet );
	}

	/**
	 * @param string $categorySnippetField
	 * @return CirrusSearchResultBuilder
	 */
	public function categorySnippetField( $categorySnippetField ): self {
		return $this->setValue( ArrayCirrusSearchResult::CATEGORY_SNIPPET_FIELD, $categorySnippetField );
	}

	/**
	 * @param MWTimestamp $timestamp
	 * @return self
	 */
	public function timestamp( MWTimestamp $timestamp ): self {
		return $this->setValue( ArrayCirrusSearchResult::TIMESTAMP, $timestamp );
	}

	/**
	 * @param int $wordCount
	 * @return self
	 */
	public function wordCount( $wordCount ): self {
		return $this->setValue( ArrayCirrusSearchResult::WORD_COUNT, $wordCount );
	}

	/**
	 * @param int $byteSize
	 * @return self
	 */
	public function byteSize( $byteSize ): self {
		return $this->setValue( ArrayCirrusSearchResult::BYTE_SIZE, $byteSize );
	}

	/**
	 * @param string $interwikiNamespaceText
	 * @return self
	 */
	public function interwikiNamespaceText( $interwikiNamespaceText ): self {
		return $this->setValue( ArrayCirrusSearchResult::INTERWIKI_NAMESPACE_TEXT, $interwikiNamespaceText );
	}

	/**
	 * @param bool $fileMatch
	 * @return self
	 */
	public function fileMatch( $fileMatch ) {
		return $this->setValue( ArrayCirrusSearchResult::IS_FILE_MATCH, $fileMatch );
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function addExtraField( string $name, $value ) {
		$this->data[ArrayCirrusSearchResult::EXTRA_FIELDS][$name] = $value;
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * @param bool $failIfExisting
	 * @return self
	 */
	private function setValue( $key, $value, $failIfExisting = true ): self {
		if ( $failIfExisting && isset( $this->data[$key] ) ) {
			throw new InvalidArgumentException( "Value $key already set cannot overwrite" );
		}
		Assert::parameter( $value !== null, '$value', 'cannot be null' );
		$this->data[$key] = $value;
		return $this;
	}
}
