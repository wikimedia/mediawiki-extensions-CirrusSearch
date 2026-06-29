<?php

namespace CirrusSearch\Maintenance;

/**
 * Immutable description of a single page to import into a test wiki.
 *
 * Produced by {@see TestCorpusSpec} from the corpus YAML; consumed by the
 * ImportTestCorpus maintenance script. Holds only data — no MediaWiki services.
 *
 * @license GPL-2.0-or-later
 */
class CorpusEntry {

	/** @var string Page title, possibly namespace-prefixed (e.g. "User:Foo/common.js"). */
	private $title;

	/** @var string|null Inline source text. Null when the source is loaded from {@see getTextFile}, or for a file with no description page text. */
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

	/**
	 * @param string $title
	 * @param string|null $text
	 * @param string|null $textFile
	 * @param string|null $file
	 * @param string|null $model
	 * @param string[] $wikis
	 * @param bool $isRedirect
	 * @param string[] $tags
	 */
	public function __construct(
		string $title,
		?string $text,
		?string $textFile,
		?string $file,
		?string $model,
		array $wikis,
		bool $isRedirect,
		array $tags
	) {
		$this->title = $title;
		$this->text = $text;
		$this->textFile = $textFile;
		$this->file = $file;
		$this->model = $model;
		$this->wikis = $wikis;
		$this->isRedirect = $isRedirect;
		$this->tags = $tags;
	}

	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * Inline source text, or null when it must be read from {@see getTextFile}
	 * (or for a file entry with no description page text).
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
}
