<?php

namespace CirrusSearch\Maintenance;

/**
 * Immutable description of a single corpus item to import into a test wiki.
 *
 * Most entries are a page (title + content) or a media file upload. An entry
 * may instead be an *XML import* ({@see isImport}): a path to a MediaWiki XML
 * dump fed to core's WikiImporter — used for content that isn't a plain page,
 * e.g. Wikibase entities.
 *
 * Produced by {@see TestCorpusSpec} from the corpus YAML; consumed by the
 * ImportTestCorpus maintenance script. Holds only data — no MediaWiki services.
 *
 * @license GPL-2.0-or-later
 */
class CorpusEntry {

	/** @var string Page title, or — for an import entry — a derived label (the XML basename). */
	private $title;

	/** @var string|null Inline source text. Null when the source is loaded from {@see getTextFile}, for a file with no description page text, or for an import entry. */
	private $text;

	/** @var string|null Path to a file holding the page source/description (resolved under the import --file-root), or null. */
	private $textFile;

	/** @var string|null Path to a media file to upload (resolved under the import --file-root), or null. */
	private $file;

	/** @var string|null Explicit content model id (e.g. "javascript"), or null to let the title decide. */
	private $model;

	/** @var string[] Logical wiki names this entry targets (e.g. ["cirrustest", "commons"]). */
	private $wikis;

	/** @var bool Whether the saved content is a redirect (affects import ordering). */
	private $isRedirect;

	/** @var string[] Tags this entry's group documents (provenance; e.g. ["@setup_main"]). */
	private $tags;

	/** @var string|null Path to a MediaWiki XML dump to import via WikiImporter (resolved under --file-root), or null. */
	private $importXml;

	/**
	 * @param string $title
	 * @param string|null $text
	 * @param string|null $textFile
	 * @param string|null $file
	 * @param string|null $model
	 * @param string[] $wikis
	 * @param bool $isRedirect
	 * @param string[] $tags
	 * @param string|null $importXml
	 */
	public function __construct(
		string $title,
		?string $text,
		?string $textFile,
		?string $file,
		?string $model,
		array $wikis,
		bool $isRedirect,
		array $tags,
		?string $importXml = null
	) {
		$this->title = $title;
		$this->text = $text;
		$this->textFile = $textFile;
		$this->file = $file;
		$this->model = $model;
		$this->wikis = $wikis;
		$this->isRedirect = $isRedirect;
		$this->tags = $tags;
		$this->importXml = $importXml;
	}

	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * Inline source text, or null when it must be read from {@see getTextFile}
	 * (or for a file entry with no description page text, or an import entry).
	 */
	public function getText(): ?string {
		return $this->text;
	}

	public function getTextFile(): ?string {
		return $this->textFile;
	}

	public function getFile(): ?string {
		return $this->file;
	}

	public function isFile(): bool {
		return $this->file !== null;
	}

	public function getModel(): ?string {
		return $this->model;
	}

	/** @return string[] */
	public function getWikis(): array {
		return $this->wikis;
	}

	public function targetsWiki( string $wiki ): bool {
		return in_array( $wiki, $this->wikis, true );
	}

	public function isRedirect(): bool {
		return $this->isRedirect;
	}

	/** @return string[] */
	public function getTags(): array {
		return $this->tags;
	}

	public function getImportXml(): ?string {
		return $this->importXml;
	}

	/** Whether this entry is a MediaWiki XML dump to import via WikiImporter. */
	public function isImport(): bool {
		return $this->importXml !== null;
	}
}
