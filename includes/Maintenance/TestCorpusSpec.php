<?php

namespace CirrusSearch\Maintenance;

use InvalidArgumentException;
use MediaWiki\Settings\Source\Format\YamlFormat;

/**
 * Parses and validates the integration-test corpus YAML into {@see CorpusEntry}
 * objects, and selects/orders the entries that belong to a given logical wiki.
 *
 * This class is intentionally free of MediaWiki services so it can be unit
 * tested without a database or service container.
 *
 * The corpus is organised into tagged groups (mirroring the per-tag
 * `BeforeOnce` blocks in the Cucumber suite). A group documents which test tag
 * its pages were used for, and may set a default wiki shared by its pages:
 *
 *     defaultWiki: cirrustest        # optional; applied to entries without `wiki`
 *     groups:
 *       - tags: ["@setup_main"]      # provenance; string or list
 *         description: Core articles used by most scenarios.
 *         pages:
 *           - title: "Catapult"
 *             text: "A [[catapult]] ..."
 *           - title: "Mangonel"
 *             redirect: "Catapult"   # convenience for "#REDIRECT [[Catapult]]"
 *       - tags: ["@commons", "@filesearch"]
 *         wiki: commons              # group-level default wiki for every page below
 *         pages:
 *           - title: "File:Foo.svg"
 *             file: ../articles/Foo.svg     # media path, resolved under --file-root
 *             text: "Description page text."
 *           - title: "User:Foo/common.js"
 *             model: javascript
 *             textFile: ../articles/foo.js  # page source read from a file
 *             tags: ["@js"]                 # optional per-page tags, added to the group's
 *
 * A flat top-level `pages:` list (no groups/tags) is also accepted for simple corpora.
 *
 * @license GPL-2.0-or-later
 */
class TestCorpusSpec {

	/** Default logical wiki applied to entries that don't specify one. */
	public const DEFAULT_WIKI = 'default';

	/** Content models accepted in the `model` field. */
	private const ALLOWED_MODELS = [ 'wikitext', 'javascript', 'css', 'json', 'text' ];

	/** @var string */
	private $defaultWiki;

	/** @var CorpusEntry[] */
	private $entries;

	/**
	 * @param string $defaultWiki
	 * @param CorpusEntry[] $entries
	 */
	private function __construct( string $defaultWiki, array $entries ) {
		$this->defaultWiki = $defaultWiki;
		$this->entries = $entries;
	}

	/**
	 * Build a spec from raw YAML text.
	 *
	 * @param string $yaml
	 * @return self
	 * @throws InvalidArgumentException on malformed input
	 */
	public static function fromYaml( string $yaml ): self {
		try {
			$data = ( new YamlFormat() )->decode( $yaml );
		} catch ( \Throwable $e ) {
			throw new InvalidArgumentException( "Unable to parse corpus YAML: " . $e->getMessage(), 0, $e );
		}
		return self::fromArray( $data );
	}

	/**
	 * Build a spec from an already-decoded associative array. Kept separate from
	 * {@see fromYaml} so it can be exercised in pure unit tests.
	 *
	 * @param array $data
	 * @return self
	 * @throws InvalidArgumentException on malformed input
	 */
	public static function fromArray( array $data ): self {
		$defaultWiki = $data['defaultWiki'] ?? self::DEFAULT_WIKI;
		if ( !is_string( $defaultWiki ) || $defaultWiki === '' ) {
			throw new InvalidArgumentException( "'defaultWiki' must be a non-empty string" );
		}

		$hasPages = array_key_exists( 'pages', $data );
		$hasGroups = array_key_exists( 'groups', $data );
		if ( $hasPages === $hasGroups ) {
			throw new InvalidArgumentException( "Corpus must contain exactly one of 'pages' or 'groups'" );
		}

		$entries = [];
		if ( $hasGroups ) {
			self::assertList( $data['groups'], 'groups' );
			foreach ( $data['groups'] as $gi => $group ) {
				self::parseGroup( $group, "groups[$gi]", $defaultWiki, $entries );
			}
		} else {
			self::parsePageList( $data['pages'], 'pages', $defaultWiki, null, [], $entries );
		}

		return new self( $defaultWiki, $entries );
	}

	/**
	 * @param mixed $group
	 * @param string $label
	 * @param string $defaultWiki
	 * @param CorpusEntry[] &$entries
	 */
	private static function parseGroup( $group, string $label, string $defaultWiki, array &$entries ): void {
		if ( !is_array( $group ) ) {
			throw new InvalidArgumentException( "$label must be a map" );
		}
		$groupTags = self::normalizeStringList( $group['tags'] ?? null, "$label: 'tags'" );
		if ( isset( $group['description'] ) && !is_string( $group['description'] ) ) {
			throw new InvalidArgumentException( "$label: 'description' must be a string" );
		}
		$groupWiki = self::normalizeWikiList( $group['wiki'] ?? null, "$label: 'wiki'" );
		if ( !array_key_exists( 'pages', $group ) ) {
			throw new InvalidArgumentException( "$label must contain a 'pages' list" );
		}
		self::parsePageList( $group['pages'], "$label.pages", $defaultWiki, $groupWiki, $groupTags, $entries );
	}

	/**
	 * @param mixed $pages
	 * @param string $label
	 * @param string $defaultWiki
	 * @param string[]|null $groupWiki Group-level wiki default, or null
	 * @param string[] $groupTags
	 * @param CorpusEntry[] &$entries
	 */
	private static function parsePageList(
		$pages, string $label, string $defaultWiki, ?array $groupWiki, array $groupTags, array &$entries
	): void {
		self::assertList( $pages, $label );
		foreach ( $pages as $i => $raw ) {
			$entries[] = self::parseEntry( $raw, "$label" . "[$i]", $defaultWiki, $groupWiki, $groupTags );
		}
	}

	/**
	 * @param mixed $raw
	 * @param string $label Position for error messages
	 * @param string $defaultWiki
	 * @param string[]|null $groupWiki
	 * @param string[] $groupTags
	 * @return CorpusEntry
	 */
	private static function parseEntry(
		$raw, string $label, string $defaultWiki, ?array $groupWiki, array $groupTags
	): CorpusEntry {
		if ( !is_array( $raw ) ) {
			throw new InvalidArgumentException( "$label must be a map" );
		}

		$title = $raw['title'] ?? null;
		if ( !is_string( $title ) || trim( $title ) === '' ) {
			throw new InvalidArgumentException( "$label is missing a non-empty 'title'" );
		}

		$file = self::optionalNonEmptyString( $raw['file'] ?? null, "$label ($title): 'file'" );
		$redirect = self::optionalNonEmptyString( $raw['redirect'] ?? null, "$label ($title): 'redirect'" );
		$textFile = self::optionalNonEmptyString( $raw['textFile'] ?? null, "$label ($title): 'textFile'" );

		$rawText = $raw['text'] ?? null;
		if ( $rawText !== null && !is_string( $rawText ) ) {
			throw new InvalidArgumentException( "$label ($title): 'text' must be a string" );
		}

		$model = $raw['model'] ?? null;
		if ( $model !== null && ( !is_string( $model ) || !in_array( $model, self::ALLOWED_MODELS, true ) ) ) {
			throw new InvalidArgumentException(
				"$label ($title): 'model' must be one of " . implode( ', ', self::ALLOWED_MODELS )
			);
		}

		// Mutually exclusive content sources.
		if ( $file !== null && $redirect !== null ) {
			throw new InvalidArgumentException( "$label ($title): cannot set both 'file' and 'redirect'" );
		}
		if ( $rawText !== null && $textFile !== null ) {
			throw new InvalidArgumentException( "$label ($title): cannot set both 'text' and 'textFile'" );
		}
		if ( $redirect !== null && ( $rawText !== null || $textFile !== null ) ) {
			throw new InvalidArgumentException( "$label ($title): 'redirect' cannot be combined with 'text'/'textFile'" );
		}

		// Resolve the content source and whether it is a redirect.
		$text = null;
		$isRedirect = false;
		if ( $redirect !== null ) {
			$text = "#REDIRECT [[" . trim( $redirect ) . "]]";
			$isRedirect = true;
			if ( $model !== null && $model !== 'wikitext' ) {
				throw new InvalidArgumentException( "$label ($title): redirects must use the wikitext model" );
			}
		} elseif ( $file !== null ) {
			// File description page; text/textFile optional.
			$text = $rawText;
		} else {
			if ( $rawText === null && $textFile === null ) {
				throw new InvalidArgumentException( "$label ($title): a non-file page requires 'text' or 'textFile'" );
			}
			$text = $rawText;
			// Redirect detection only applies to inline text; a redirect whose source is loaded via
			// textFile is not detected here (the file isn't read in this service-free layer) and so
			// won't be ordered as a redirect. Prefer the explicit `redirect:` field for redirects.
			$isRedirect = $rawText !== null && self::looksLikeRedirect( $rawText );
		}

		// Wiki resolution: per-page overrides group default overrides corpus default.
		$pageWiki = self::normalizeWikiList( $raw['wiki'] ?? null, "$label ($title): 'wiki'" );
		$wikis = $pageWiki ?? $groupWiki ?? [ $defaultWiki ];

		// Tags: group tags plus any per-page tags, de-duplicated.
		$pageTags = self::normalizeStringList( $raw['tags'] ?? null, "$label ($title): 'tags'" );
		$tags = array_values( array_unique( array_merge( $groupTags, $pageTags ) ) );

		return new CorpusEntry( $title, $text, $textFile, $file, $model, $wikis, $isRedirect, $tags );
	}

	/**
	 * @param mixed $value
	 * @param string $label
	 * @return string|null
	 */
	private static function optionalNonEmptyString( $value, string $label ): ?string {
		if ( $value === null ) {
			return null;
		}
		if ( !is_string( $value ) || $value === '' ) {
			throw new InvalidArgumentException( "$label must be a non-empty string" );
		}
		return $value;
	}

	/**
	 * Normalize a wiki value (string or list) to a de-duplicated list, or null
	 * when unspecified/empty (so the caller can fall back to a default).
	 *
	 * @param mixed $raw
	 * @param string $label
	 * @return string[]|null
	 */
	private static function normalizeWikiList( $raw, string $label ): ?array {
		$list = self::normalizeStringList( $raw, $label, 'wiki' );
		return $list === [] ? null : $list;
	}

	/**
	 * Normalize a scalar-or-list value to a de-duplicated list of non-empty strings.
	 *
	 * @param mixed $raw
	 * @param string $label
	 * @param string $what Noun for error messages (default 'tags')
	 * @return string[]
	 */
	private static function normalizeStringList( $raw, string $label, string $what = 'tags' ): array {
		if ( $raw === null ) {
			return [];
		}
		$list = is_array( $raw ) ? $raw : [ $raw ];
		$out = [];
		foreach ( $list as $v ) {
			if ( !is_string( $v ) || $v === '' ) {
				throw new InvalidArgumentException( "$label: '$what' entries must be non-empty strings" );
			}
			$out[] = $v;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed $value
	 * @param string $label
	 */
	private static function assertList( $value, string $label ): void {
		if ( !is_array( $value ) || ( $value !== [] && !array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( "'$label' must be a list" );
		}
	}

	private static function looksLikeRedirect( string $text ): bool {
		return preg_match( '/^\s*#REDIRECT/i', $text ) === 1;
	}

	public function getDefaultWiki(): string {
		return $this->defaultWiki;
	}

	/**
	 * All entries, in declaration order.
	 *
	 * @return CorpusEntry[]
	 */
	public function getEntries(): array {
		return $this->entries;
	}

	/**
	 * The distinct logical wikis referenced anywhere in the corpus, sorted.
	 * Lets a caller detect a mistyped target wiki (which would select nothing).
	 *
	 * @return string[]
	 */
	public function knownWikis(): array {
		$seen = [];
		foreach ( $this->entries as $e ) {
			foreach ( $e->getWikis() as $w ) {
				$seen[$w] = true;
			}
		}
		$wikis = array_keys( $seen );
		sort( $wikis );
		return $wikis;
	}

	/**
	 * Entries targeting the given logical wiki (defaults to the corpus default),
	 * ordered so that plain pages and files come before redirects. This is a
	 * best-effort convenience for the common single-hop "redirect -> content" case
	 * (MediaWiki saves redirects to missing targets fine, and ForceSearchIndex
	 * re-derives the index afterwards regardless); chained redirect-to-redirect
	 * targets are NOT topologically ordered.
	 *
	 * @param string|null $wiki
	 * @return CorpusEntry[]
	 */
	public function entriesForWiki( ?string $wiki = null ): array {
		$wiki ??= $this->defaultWiki;
		$selected = array_values( array_filter(
			$this->entries,
			static fn ( CorpusEntry $e ) => $e->targetsWiki( $wiki )
		) );
		// usort is stable since PHP 8.0, so declaration order is preserved within each group.
		usort(
			$selected,
			static fn ( CorpusEntry $a, CorpusEntry $b ) => ( $a->isRedirect() ? 1 : 0 ) <=> ( $b->isRedirect() ? 1 : 0 )
		);
		return $selected;
	}
}
