<?php
namespace CirrusSearch\Jenkins;

use \JobQueueAggregator;
use \JobQueueGroup;

/**
 * Sets up configuration required to run the browser tests on Jenkins.
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

// Configuration we have to override before installing Cirrus but only if we're using
// Jenkins as a prototype for development.

// Extra Cirrus stuff for Jenkins
$wgAutoloadClasses[ 'CirrusSearch\Jenkins\CleanSetup' ] = __DIR__ . '/cleanSetup.php';
$wgAutoloadClasses[ 'CirrusSearch\Jenkins\NukeAllIndexes' ] = __DIR__ . '/nukeAllIndexes.php';
$wgHooks[ 'LoadExtensionSchemaUpdates' ][] = 'CirrusSearch\Jenkins\Jenkins::installDatabaseUpdatePostActions';
$wgHooks[ 'BeforeInitialize' ][] = 'CirrusSearch\Jenkins\Jenkins::recyclePruneAndUndelayJobs';
$wgHooks[ 'PageContentLanguage' ][] = 'CirrusSearch\Jenkins\Jenkins::setLanguage';

// Dependencies
// Jenkins will automatically load these for us but it makes this file more generally useful
// to require them ourselves.
require_once( "$IP/extensions/Elastica/Elastica.php" );
require_once( "$IP/extensions/MwEmbedSupport/MwEmbedSupport.php" );
require_once( "$IP/extensions/TimedMediaHandler/TimedMediaHandler.php" );
require_once( "$IP/extensions/PdfHandler/PdfHandler.php" );
require_once( "$IP/extensions/Cite/Cite.php" );

// Configuration
$wgSearchType = 'CirrusSearch';
$wgCirrusSearchUseExperimentalHighlighter = true;
$wgCirrusSearchOptimizeIndexForExperimentalHighlighter = true;
$wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] = array( 'build', 'use' );
$wgCirrusSearchWikimediaExtraPlugin[ 'safer' ] = array(
	'phrase' => array(
	)
);

$wgOggThumbLocation = '/usr/bin/oggThumb';
$wgGroupPermissions[ '*' ][ 'deleterevision' ] = true;
$wgFileExtensions[] = 'pdf';
$wgFileExtensions[] = 'svg';
$wgCapitalLinks = false;
$wgUseInstantCommons = true;
$wgEnableUploads = true;
$wgJobTypeConf['default'] = array(
	'class' => 'JobQueueRedis',
	'daemonized'  => true,
	'order' => 'fifo',
	'redisServer' => 'localhost',
	'checkDelay' => true,
	'redisConfig' => array(
		'password' => '',
	),
);
$wgJobQueueAggregator = array(
	'class'       => 'JobQueueAggregatorRedis',
	'redisServer' => 'localhost',
	'redisConfig' => array(
		'password' => '',
	),
);
$wgCiteEnablePopups = true;
$wgExtraNamespaces[ 760 ] = 'Mó';

// Extra helpful configuration but not really required
$wgShowExceptionDetails = true;

$wgCirrusSearchLanguageWeight[ 'user' ] = 10.0;
$wgCirrusSearchLanguageWeight[ 'wiki' ] = 5.0;

if ( class_exists( 'PoolCounter_Client' ) ) {
	// If the pool counter is around set up prod like pool counter settings
	$wgPoolCounterConf[ 'CirrusSearch-Search' ] = array(
		'class' => 'PoolCounter_Client',
		'timeout' => 15,
		'workers' => 432,
		'maxqueue' => 600,
	);
	// Super common and mostly fast
	$wgPoolCounterConf[ 'CirrusSearch-Prefix' ] = array(
		'class' => 'PoolCounter_Client',
		'timeout' => 15,
		'workers' => 432,
		'maxqueue' => 600,
	);
	// Regex searches are much heavier then regular searches so we limit the
	// concurrent number.
	$wgPoolCounterConf[ 'CirrusSearch-Regex' ] = array(
		'class' => 'PoolCounter_Client',
		'timeout' => 60,
		'workers' => 10,
		'maxqueue' => 20,
	);
	// These should be very very fast and reasonably rare
	$wgPoolCounterConf[ 'CirrusSearch-NamespaceLookup' ] = array(
		'class' => 'PoolCounter_Client',
		'timeout' => 5,
		'workers' => 50,
		'maxqueue' => 200,
	);
	// Can't be enabled until poolcounter gets Ie282b8486c7bad451fbc5fb9a8274c6e01a728a7.
	// TODO enable this.
	// $wgPoolCounterConf[ 'CirrusSearch-PerUser' ] = array(
	// 	'class' => 'PoolCounter_Client',
	// 	'timeout' => 0,
	// 	'workers' => 1,
	// 	'maxqueue' => 1,
	// );
}

class Jenkins {
	/**
	 * Installs maintenance scripts that provide a clean Elasticsearch index for testing.
	 * @param DatabaseUpdater $updater database updater
	 * @return bool true so we let other extensions install more maintenance actions
	 */
	public static function installDatabaseUpdatePostActions( $updater ) {
		$updater->addPostDatabaseUpdateMaintenance( 'CirrusSearch\Jenkins\NukeAllIndexes' );
		$updater->addPostDatabaseUpdateMaintenance( 'CirrusSearch\Jenkins\CleanSetup' );
		return true;
	}

	public static function recyclePruneAndUndelayJobs( $special, $subpage ) {
		$jobsToUndelay = array(
			'cirrusSearchIncomingLinkCount',
			'cirrusSearchLinksUpdate',
			'cirrusSearchLinksUpdatePrioritized'
		);
		foreach ( $jobsToUndelay as $type ) {
			$jobQueue = JobQueueGroup::singleton()->get( $type );
			if ( !$jobQueue ) {
				continue;
			}
			$count = $jobQueue->recyclePruneAndUndelayJobs();
			if ( !$count ) {
				continue;
			}
			JobQueueAggregator::singleton()->notifyQueueNonEmpty( $jobQueue->getWiki(), $type );
		}
	}

	/**
	 * If the page ends in '/<language code>' then set the page's language to that code.
	 * @param Title @title page title object
	 * @param string|Language $pageLang the page content language (either an object or a language code)
	 * @param Language $wgLang the user language
	 */
	public static function setLanguage( $title, &$pageLang, $wgLang ) {
		$matches = array();
		if ( preg_match( '/\/..$/', $title->getText(), $matches ) ) {
			$pageLang = substr( $matches[ 0 ], 1 );
		}
		return true;
	}
}
