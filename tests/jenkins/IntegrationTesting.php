<?php

namespace CirrusSearch\Jenkins;

use Language;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Sets up configuration expected by the CirrusSearch integration test suite.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

// All of this has to be done at setup time so it has the right globals.  No putting
// it in a class or anything.

require_once __DIR__ . '/FullyFeaturedConfig.php';

// Extra Cirrus stuff for Jenkins
$wgAutoloadClasses['CirrusSearch\Jenkins\CleanSetup'] = __DIR__ . '/cleanSetup.php';
$wgAutoloadClasses['CirrusSearch\Jenkins\NukeAllIndexes'] = __DIR__ . '/nukeAllIndexes.php';
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

class IntegrationTesting {
	/**
	 * Installs maintenance scripts that provide a clean Elasticsearch index for testing.
	 * @param DatabaseUpdater $updater
	 * @return bool true so we let other extensions install more maintenance actions
	 */
	public static function installDatabaseUpdatePostActions( $updater ) {
		// NukeAllIndexes nukes indices unrelated to this wiki, meaning we can't run update.php
		// for each wiki.
		// $updater->addPostDatabaseUpdateMaintenance( NukeAllIndexes::class );
		$updater->addPostDatabaseUpdateMaintenance( CleanSetup::class );
		return true;
	}

	/**
	 * If the page ends in '/<language code>' then set the page's language to that code.
	 * @param Title $title
	 * @param string|Language|StubUserLang &$pageLang the page content language
	 * @param Language|StubUserLang $wgLang the user language
	 */
	public static function setLanguage( $title, &$pageLang, $wgLang ) {
		$matches = [];
		if ( preg_match( '/\/..$/', $title->getText(), $matches ) ) {
			$pageLang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( substr( $matches[0], 1 ) );
		}
	}
}
