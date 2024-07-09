<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\CirrusSearch;
use CirrusSearch\Search\CirrusIndexField;
use CirrusSearch\SearchConfig;
use Elastica\Document;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use WikiPage;

/**
 * Extract searchable properties from the MediaWiki ParserOutput
 */
class ParserOutputPageProperties implements PagePropertyBuilder {
	/** @var SearchConfig */
	private $config;

	/**
	 * @param SearchConfig $config
	 */
	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * {@inheritDoc}
	 */
	public function initialize( Document $doc, WikiPage $page, RevisionRecord $revision ): void {
		// NOOP
	}

	/**
	 * {@inheritDoc}
	 */
	public function finishInitializeBatch(): void {
		// NOOP
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( Document $doc, Title $title, RevisionRecord $revision ): void {
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$this->finalizeReal( $doc, $page, new CirrusSearch, $revision );
	}

	/**
	 * Visible for testing. Much simpler to test with all objects resolved.
	 *
	 * @param Document $doc Document to finalize
	 * @param WikiPage $page WikiPage to scope operation to
	 * @param CirrusSearch $engine SearchEngine implementation
	 * @param RevisionRecord $revision The page revision to use
	 * @throws BuildDocumentException
	 */
	public function finalizeReal(
		Document $doc,
		WikiPage $page,
		CirrusSearch $engine,
		RevisionRecord $revision
	): void {
		$wanCache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cacheKey = $wanCache->makeKey(
			'CirrusSearchParserOutputPageProperties',
			$page->getId(),
			$revision->getId(),
			$page->getTouched(),
			'v2'
		);

		// We are having problems with low hit rates, but haven't been able to
		// track down why that is. Log a sample of keys so we can evaluate if
		// the problem is that $page->getTouched() is changing between
		// invocations. -- eb 2024 july 9
		if ( $page->getId() % 1000 === 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->debug(
				'Sampling of CirrusSearchParserOutputPageProperties cache keys: {cache_key}',
				[
					'cache_key' => $cacheKey,
					'revision_id' => $revision->getId(),
					'page_id' => $page->getId(),
				] );
		}

		$fieldContent = $wanCache->getWithSetCallback(
			$cacheKey,
			ExpirationAwareness::TTL_HOUR * 6,
			function () use ( $page, $revision, $engine ) {
				$contentHandler = $page->getContentHandler();
				// TODO: Should see if we can change content handler api to avoid
				// the WikiPage god object, but currently parser cache is still
				// tied to WikiPage as well.
				$output = $contentHandler->getParserOutputForIndexing( $page, null, $revision );

				if ( !$output ) {
					throw new BuildDocumentException( "ParserOutput cannot be obtained." );
				}

				$fieldContent = $contentHandler->getDataForSearchIndex( $page, $output, $engine, $revision );
				$fieldContent['display_title'] = self::extractDisplayTitle( $page->getTitle(), $output );
				return self::fixAndFlagInvalidUTF8InSource( $fieldContent, $page->getId() );
			}
		);
		$fieldContent = $this->truncateFileContent( $fieldContent );
		$fieldDefinitions = $engine->getSearchIndexFields();
		foreach ( $fieldContent as $field => $fieldData ) {
			$doc->set( $field, $fieldData );
			if ( isset( $fieldDefinitions[$field] ) ) {
				$hints = $fieldDefinitions[$field]->getEngineHints( $engine );
				CirrusIndexField::addIndexingHints( $doc, $field, $hints );
			}
		}
	}

	/**
	 * @param Title $title
	 * @param ParserOutput $output
	 * @return string|null
	 */
	private static function extractDisplayTitle( Title $title, ParserOutput $output ): ?string {
		$titleText = $title->getText();
		$titlePrefixedText = $title->getPrefixedText();

		$raw = $output->getDisplayTitle();
		if ( $raw === false ) {
			return null;
		}
		$clean = Sanitizer::stripAllTags( $raw );
		// Only index display titles that differ from the normal title
		if ( self::isSameString( $clean, $titleText ) ||
			self::isSameString( $clean, $titlePrefixedText )
		) {
			return null;
		}
		if ( $title->getNamespace() === 0 || strpos( $clean, ':' ) === false ) {
			return $clean;
		}
		// There is no official way that namespaces work in display title, it
		// is an arbitrary string. Even so some use cases, such as the
		// Translate extension, will translate the namespace as well. Here
		// `Help:foo` will have a display title of `Aide:bar`. If we were to
		// simply index as is the autocomplete and near matcher would see
		// Help:Aide:bar, which doesn't seem particularly useful.
		// The strategy here is to see if the portion before the : is a valid namespace
		// in either the language of the wiki or the language of the page. If it is
		// then we strip it from the display title.
		[ $maybeNs, $maybeDisplayTitle ] = explode( ':', $clean, 2 );
		$cleanTitle = Title::newFromText( $clean );
		if ( $cleanTitle === null ) {
			// The title is invalid, we cannot extract the ns prefix
			return $clean;
		}
		if ( $cleanTitle->getNamespace() == $title->getNamespace() ) {
			// While it doesn't really matter, $cleanTitle->getText() may
			// have had ucfirst() applied depending on settings so we
			// return the unmodified $maybeDisplayTitle.
			return $maybeDisplayTitle;
		}

		$docLang = $title->getPageLanguage();
		$nsIndex = $docLang->getNsIndex( $maybeNs );
		if ( $nsIndex !== $title->getNamespace() ) {
			// Valid namespace but not the same as the actual page.
			// Keep the namespace in the display title.
			return $clean;
		}

		return self::isSameString( $maybeDisplayTitle, $titleText )
			? null
			: $maybeDisplayTitle;
	}

	private static function isSameString( string $a, string $b ): bool {
		$a = mb_strtolower( strtr( $a, '_', ' ' ) );
		$b = mb_strtolower( strtr( $b, '_', ' ' ) );
		return $a === $b;
	}

	/**
	 * Find invalid UTF-8 sequence in the source text.
	 * Fix them and flag the doc with the CirrusSearchInvalidUTF8 template.
	 *
	 * Temporary solution to help investigate/fix T225200
	 *
	 * Visible for testing only
	 * @param array $fieldDefinitions
	 * @param int $pageId
	 * @return array
	 */
	public static function fixAndFlagInvalidUTF8InSource( array $fieldDefinitions, int $pageId ): array {
		if ( isset( $fieldDefinitions['source_text'] ) ) {
			$fixedVersion = mb_convert_encoding( $fieldDefinitions['source_text'], 'UTF-8', 'UTF-8' );
			if ( $fixedVersion !== $fieldDefinitions['source_text'] ) {
				LoggerFactory::getInstance( 'CirrusSearch' )
					->warning( 'Fixing invalid UTF-8 sequences in source text for page id {page_id}',
						[ 'page_id' => $pageId ] );
				$fieldDefinitions['source_text'] = $fixedVersion;
				$fieldDefinitions['template'][] = Title::makeTitle( NS_TEMPLATE, 'CirrusSearchInvalidUTF8' )->getPrefixedText();
			}
		}
		return $fieldDefinitions;
	}

	/**
	 * Visible for testing only
	 * @param int $maxLen
	 * @param array $fieldContent
	 * @return array
	 */
	public static function truncateFileTextContent( int $maxLen, array $fieldContent ): array {
		if ( $maxLen >= 0 && isset( $fieldContent['file_text'] ) && strlen( $fieldContent['file_text'] ) > $maxLen ) {
			$fieldContent['file_text'] = mb_strcut( $fieldContent['file_text'], 0, $maxLen );
		}

		return $fieldContent;
	}

	/**
	 * @param array $fieldContent
	 * @return array
	 */
	private function truncateFileContent( array $fieldContent ): array {
		return self::truncateFileTextContent( $this->config->get( 'CirrusSearchMaxFileTextLength' ) ?: -1, $fieldContent );
	}
}
