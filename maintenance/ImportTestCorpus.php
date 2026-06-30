<?php

namespace CirrusSearch\Maintenance;

use InvalidArgumentException;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Import\ImportStreamSource;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * Bulk-import a YAML test corpus (pages and media files) into the wiki database.
 *
 * This is a development / integration-test helper, NOT an operator tool. It is
 * the fast path for preparing the CirrusSearch end-to-end test environment:
 * instead of creating every page over the API and waiting for each to be
 * indexed, the corpus is written straight to the DB while search updates are
 * disabled, after which the whole wiki is indexed in one pass.
 *
 * Intended usage (per wiki):
 *   # environment: $wgDisableSearchUpdate = true
 *   run.php --wiki <db> CirrusSearch:ImportTestCorpus --corpus corpus.yaml --target-wiki <logical>
 *   run.php --wiki <db> CirrusSearch:UpdateSearchIndexConfig
 *   run.php --wiki <db> CirrusSearch:ForceSearchIndex
 *
 * @license GPL-2.0-or-later
 */

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

class ImportTestCorpus extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			"Bulk-import a YAML test corpus (pages + media files) into the wiki database.\n" .
			"Development / integration-test use only. Run with \$wgDisableSearchUpdate = true, then index\n" .
			"with CirrusSearch:UpdateSearchIndexConfig + CirrusSearch:ForceSearchIndex."
		);
		$this->addOption( 'corpus', 'Path to the corpus YAML file', true, true );
		$this->addOption( 'target-wiki',
			'Logical wiki name whose entries to import (default: the corpus defaultWiki)', false, true );
		$this->addOption( 'file-root',
			'Base directory for resolving relative file paths (default: the corpus file directory)', false, true );
		$this->addOption( 'dry-run', 'Parse and report what would be imported without writing anything' );
		$this->setBatchSize( 50 );
	}

	/** @inheritDoc */
	public function execute() {
		// This script only populates the database. Stopping CirrusSearch from propagating per-page
		// index updates during the bulk import is the caller's responsibility (e.g. set
		// $wgDisableSearchUpdate in the environment for the prep phase, or hold the job runner): it
		// cannot be done reliably from here because the index jobs run in a separate process.
		$corpusPath = $this->getOption( 'corpus' );
		if ( !is_readable( $corpusPath ) ) {
			$this->fatalError( "Cannot read corpus file: $corpusPath" );
		}

		try {
			$spec = TestCorpusSpec::fromYaml( file_get_contents( $corpusPath ) );
		} catch ( InvalidArgumentException $e ) {
			$this->fatalError( $e->getMessage() );
		}

		$targetWiki = $this->getOption( 'target-wiki', $spec->getDefaultWiki() );
		$fileRoot = $this->getOption( 'file-root', dirname( (string)realpath( $corpusPath ) ) );
		$dryRun = $this->hasOption( 'dry-run' );

		$entries = $spec->entriesForWiki( $targetWiki );
		$this->output( "Importing " . count( $entries ) . " entries for wiki '$targetWiki'" .
			( $dryRun ? ' (dry run)' : '' ) . "\n" );
		if ( $entries === [] ) {
			// Fail loudly-ish: a mistyped --target-wiki or an empty corpus would otherwise import
			// nothing and exit 0, silently leaving the wiki unindexed for the test run.
			$known = $spec->knownWikis();
			$this->output( "WARNING: no corpus entries target wiki '$targetWiki'. " .
				( $known
					? "The corpus targets these wikis: " . implode( ', ', $known ) . " (check --target-wiki?)."
					: "The corpus is empty." ) . "\n" );
		}

		$user = User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
		if ( !$user ) {
			$this->fatalError( "Unable to create the maintenance system user" );
		}

		$stats = [ 'created' => 0, 'updated' => 0, 'uploaded' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0 ];
		$processed = 0;
		foreach ( $entries as $entry ) {
			if ( $entry->isImport() ) {
				$this->importXmlEntry( $entry, $fileRoot, $user, $dryRun, $stats );
			} elseif ( $entry->isFile() ) {
				$this->importFile( $entry, $fileRoot, $user, $dryRun, $stats );
			} else {
				$this->importPage( $entry, $fileRoot, $user, $dryRun, $stats );
			}
			// Flush deferred updates (e.g. LinksUpdate) periodically so the pagelinks /
			// categorylinks tables are populated before ForceSearchIndex reads them.
			if ( !$dryRun && ( ++$processed % $this->getBatchSize() === 0 ) ) {
				DeferredUpdates::doUpdates();
			}
		}
		if ( !$dryRun ) {
			DeferredUpdates::doUpdates();
		}

		$this->output( sprintf(
			"Done. created=%d updated=%d uploaded=%d imported=%d skipped=%d failed=%d\n",
			$stats['created'], $stats['updated'], $stats['uploaded'], $stats['imported'],
			$stats['skipped'], $stats['failed']
		) );

		return $stats['failed'] === 0;
	}

	/**
	 * @param CorpusEntry $entry
	 * @param string $fileRoot
	 * @param User $user
	 * @param bool $dryRun
	 * @param array<string,int> &$stats
	 */
	private function importPage( CorpusEntry $entry, string $fileRoot, User $user, bool $dryRun, array &$stats ): void {
		$title = Title::newFromText( $entry->getTitle() );
		if ( !$title || $title->hasFragment() || !$title->canExist() ) {
			$this->error( "  invalid title '{$entry->getTitle()}', skipping\n" );
			$stats['skipped']++;
			return;
		}
		if ( $title->getNamespace() === NS_FILE ) {
			// Reached importPage (not importFile) so there is no 'file:' — likely an authoring slip.
			$this->output( "  note: {$title->getPrefixedText()} is a File: page with no 'file:'; " .
				"importing the description page only (no media upload)\n" );
		}

		$source = $this->loadContent( $entry, $fileRoot );
		if ( $source === false ) {
			$this->error( "  cannot read textFile '{$entry->getTextFile()}' for {$title->getPrefixedText()}\n" );
			$stats['failed']++;
			return;
		}

		$existed = $title->exists();
		if ( $dryRun ) {
			$this->output( "  [dry-run] would " . ( $existed ? 'update' : 'create' ) .
				" {$title->getPrefixedText()}\n" );
			$stats[$existed ? 'updated' : 'created']++;
			return;
		}

		try {
			$content = ContentHandler::makeContent( $source, $title, $entry->getModel() );
			$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
			$updater = $page->newPageUpdater( $user );
			$updater->setContent( SlotRecord::MAIN, $content );
			$updater->saveRevision(
				CommentStoreComment::newUnsavedComment( 'Import test corpus' ),
				EDIT_INTERNAL | EDIT_SUPPRESS_RC | EDIT_FORCE_BOT
			);

			if ( !$updater->wasSuccessful() ) {
				$this->error( "  failed to save {$title->getPrefixedText()}: " .
					$updater->getStatus()->__toString() . "\n" );
				$stats['failed']++;
				return;
			}
		} catch ( \Throwable $e ) {
			$this->error( "  failed to save {$title->getPrefixedText()}: " . $e->getMessage() . "\n" );
			$stats['failed']++;
			return;
		}

		if ( !$updater->wasRevisionCreated() ) {
			$this->output( "  unchanged {$title->getPrefixedText()}\n" );
			$stats['skipped']++;
		} else {
			$this->output( "  " . ( $existed ? 'updated' : 'created' ) . " {$title->getPrefixedText()}\n" );
			$stats[$existed ? 'updated' : 'created']++;
		}
	}

	/**
	 * @param CorpusEntry $entry
	 * @param string $fileRoot
	 * @param User $user
	 * @param bool $dryRun
	 * @param array<string,int> &$stats
	 */
	private function importFile( CorpusEntry $entry, string $fileRoot, User $user, bool $dryRun, array &$stats ): void {
		$title = Title::newFromText( $entry->getTitle() );
		if ( !$title || $title->getNamespace() !== NS_FILE ) {
			$this->error( "  file entry '{$entry->getTitle()}' must be in the File: namespace, skipping\n" );
			$stats['skipped']++;
			return;
		}

		$srcPath = $this->resolveFilePath( $fileRoot, (string)$entry->getFile() );
		if ( $srcPath === null ) {
			$this->error( "  cannot read media file '{$entry->getFile()}' for {$title->getPrefixedText()}\n" );
			$stats['failed']++;
			return;
		}

		$pageText = $this->loadContent( $entry, $fileRoot );
		if ( $pageText === false ) {
			$this->error( "  cannot read textFile '{$entry->getTextFile()}' for {$title->getPrefixedText()}\n" );
			$stats['failed']++;
			return;
		}

		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = $repo->newFile( $title );

		if ( $dryRun ) {
			if ( $file->exists() ) {
				$this->output( "  [dry-run] {$title->getPrefixedText()} already exists, would skip\n" );
				$stats['skipped']++;
			} else {
				$this->output( "  [dry-run] would upload {$title->getPrefixedText()} from $srcPath\n" );
				$stats['uploaded']++;
			}
			return;
		}

		if ( $file->exists() ) {
			$this->output( "  {$title->getPrefixedText()} already exists, skipping upload\n" );
			$stats['skipped']++;
			return;
		}

		try {
			// flags = 0: never delete the source fixture file.
			$status = $file->upload(
				$srcPath,
				'Import test corpus',
				$pageText,
				0,
				false,
				false,
				$user
			);
		} catch ( \Throwable $e ) {
			$this->error( "  failed to upload {$title->getPrefixedText()}: " . $e->getMessage() . "\n" );
			$stats['failed']++;
			return;
		}

		if ( !$status->isOK() ) {
			$this->error( "  failed to upload {$title->getPrefixedText()}: " . $status->__toString() . "\n" );
			$stats['failed']++;
			return;
		}

		$this->output( "  uploaded {$title->getPrefixedText()}\n" );
		$stats['uploaded']++;
	}

	/**
	 * Import a MediaWiki XML dump (e.g. a Wikibase entity) via core's WikiImporter.
	 *
	 * @param CorpusEntry $entry
	 * @param string $fileRoot
	 * @param User $user
	 * @param bool $dryRun
	 * @param array<string,int> &$stats
	 */
	private function importXmlEntry( CorpusEntry $entry, string $fileRoot, User $user, bool $dryRun, array &$stats ): void {
		$path = $this->resolveFilePath( $fileRoot, (string)$entry->getImportXml() );
		if ( $path === null ) {
			$this->error( "  cannot read import XML '{$entry->getImportXml()}'\n" );
			$stats['failed']++;
			return;
		}

		if ( $dryRun ) {
			$this->output( "  [dry-run] would import XML $path\n" );
			$stats['imported']++;
			return;
		}

		try {
			$handle = fopen( $path, 'rt' );
			if ( $handle === false ) {
				$this->error( "  cannot open import XML $path\n" );
				$stats['failed']++;
				return;
			}
			$importer = $this->getServiceContainer()->getWikiImporterFactory()
				->getWikiImporter( new ImportStreamSource( $handle ), new UltimateAuthority( $user ) );
			$importer->disableStatisticsUpdate();
			$ok = $importer->doImport();
		} catch ( \Throwable $e ) {
			$this->error( "  failed to import XML $path: " . $e->getMessage() . "\n" );
			$stats['failed']++;
			return;
		}

		if ( !$ok ) {
			$this->error( "  import of XML $path reported failure\n" );
			$stats['failed']++;
			return;
		}

		$this->output( "  imported XML $path\n" );
		$stats['imported']++;
	}

	/**
	 * Resolve an entry's source/description text, reading its textFile if set.
	 *
	 * @param CorpusEntry $entry
	 * @param string $fileRoot
	 * @return string|false The text (empty string when none is specified), or false if a textFile could not be read.
	 */
	private function loadContent( CorpusEntry $entry, string $fileRoot ) {
		$textFile = $entry->getTextFile();
		if ( $textFile !== null ) {
			$path = $this->resolveFilePath( $fileRoot, $textFile );
			if ( $path === null ) {
				return false;
			}
			return (string)file_get_contents( $path );
		}
		return $entry->getText() ?? '';
	}

	/**
	 * Resolve a corpus file reference to a readable absolute path.
	 *
	 * @param string $fileRoot
	 * @param string $file
	 * @return string|null Absolute path, or null if it cannot be read
	 */
	private function resolveFilePath( string $fileRoot, string $file ): ?string {
		$path = str_starts_with( $file, '/' ) ? $file : rtrim( $fileRoot, '/' ) . '/' . $file;
		$real = realpath( $path );
		if ( $real === false || !is_readable( $real ) ) {
			return null;
		}
		return $real;
	}
}

// @codeCoverageIgnoreStart
$maintClass = ImportTestCorpus::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
