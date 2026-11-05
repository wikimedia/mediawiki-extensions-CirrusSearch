<?php

namespace CirrusSearch\Jenkins;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Sets up configuration expected by the CirrusSearch integration test suite.
 *
 * @license GPL-2.0-or-later
 */

// All of this has to be done at setup time so it has the right globals.  No putting
// it in a class or anything.

require_once __DIR__ . '/FullyFeaturedConfig.php';

// Extra Cirrus stuff for Jenkins
$wgAutoloadClasses['CirrusSearch\Jenkins\CleanSetup'] = __DIR__ . '/cleanSetup.php';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'CirrusSearch\Jenkins\IntegrationTesting::installDatabaseUpdatePostActions';
$wgHooks['PageContentLanguage'][] = 'CirrusSearch\Jenkins\IntegrationTesting::setLanguage';

// Dependencies
// Jenkins will automatically load these for us but it makes this file more generally useful
// to require them ourselves.
wfLoadExtension( 'TimedMediaHandler' );
wfLoadExtension( 'PdfHandler' );
wfLoadExtension( 'Cite' );
wfLoadExtension( 'SiteMatrix' );

// Configuration
$wgGroupPermissions['*']['deleterevision'] = true;
$wgFileExtensions[] = 'pdf';
$wgFileExtensions[] = 'svg';
$wgCapitalLinks = false;
$wgEnableUploads = true;
$wgCiteEnablePopups = true;
$wgExtraNamespaces[760] = 'Mó';
$wgMaxArticleSize = 100;

// Extra helpful configuration but not really required
$wgShowExceptionDetails = true;

$wgCirrusSearchLanguageWeight['user'] = 10.0;
$wgCirrusSearchLanguageWeight['wiki'] = 5.0;
$wgCirrusSearchAllowLeadingWildcard = false;
// $wgCirrusSearchInterwikiSources['c'] = 'commonswiki';

// Test only API action to run the completion suggester build process
$wgAPIModules['cirrus-suggest-index'] = 'CirrusSearch\Api\SuggestIndex';
// Bring the ElasticWrite backoff down to between 2^-1 and 2^3 seconds during browser tests
$wgCirrusSearchWriteBackoffExponent = -1;
$wgCirrusSearchUseCompletionSuggester = "yes";

// First-class redirect documents: build the redirect's own document and use the
// page_type:redirect filter so redirects are hidden from normal search results
// (exercised by redirect_documents_api.feature).
$wgCirrusSearchRedirectDocuments = [ 'build' => true, 'use' => true ];

// Semantic search (semantic_api.feature). Only the cirrusSemanticSearch debug option puts a
// query on the semantic route. Every image gets the mapping and the passages, but the
// scenarios need ml-commons and only run on 3.x.
$wgCirrusSearchDefaultSemanticProfile = 'default_semantic';
// Same cluster, but this runs the override code that prod probably uses to reach its
// semantic cluster.
$wgCirrusSearchClusterOverrides['semantic'] = 'default';
// A knn query fails on an index that was created without it.
$wgCirrusSearchExtraIndexSettings['index.knn'] = true;
$wgAutoloadClasses['CirrusSearch\Jenkins\FakeInference'] = __DIR__ . '/FakeInference.php';
$wgAutoloadClasses['CirrusSearch\Jenkins\PassageEmbeddings'] = __DIR__ . '/PassageEmbeddings.php';
$wgHooks['SearchIndexFields'][] = 'CirrusSearch\Jenkins\PassageEmbeddings::onSearchIndexFields';
$wgHooks['SearchDataForIndex2'][] = 'CirrusSearch\Jenkins\PassageEmbeddings::onSearchDataForIndex2';

class IntegrationTesting {
	/**
	 * Installs maintenance scripts that provide a clean Elasticsearch index for testing.
	 * @param DatabaseUpdater $updater
	 * @return bool true so we let other extensions install more maintenance actions
	 */
	public static function installDatabaseUpdatePostActions( $updater ) {
		$updater->addPostDatabaseUpdateMaintenance( CleanSetup::class );
		return true;
	}

	/**
	 * If the page ends in '/<language code>' then set the page's language to that code.
	 * @param Title $title
	 * @param string|Language|StubUserLang &$pageLang the page content language
	 * @param Language|StubUserLang $userLang the user language
	 */
	public static function setLanguage( $title, &$pageLang, $userLang ) {
		$matches = [];
		if ( preg_match( '/\/..$/', $title->getText(), $matches ) ) {
			$pageLang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( substr( $matches[0], 1 ) );
		}
	}
}
