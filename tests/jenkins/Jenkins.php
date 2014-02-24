<?php
namespace CirrusSearch\Jenkins;

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
if ( !isset( $wgRedisPassword ) ) {
	$wgRedisPassword = 'notsecure';
}

// Extra Cirrus stuff for Jenkins
$wgAutoloadClasses[ 'CirrusSearch\Jenkins\CleanSetup' ] = __DIR__ . '/cleanSetup.php';
$wgHooks[ 'LoadExtensionSchemaUpdates' ][] = 'CirrusSearch\Jenkins\Jenkins::installCleanSetup';

// Dependencies
// Jenkins will automatically load these for us but it makes this file more generally useful
// to require them ourselves.
require_once( "$IP/extensions/Elastica/Elastica.php" );
require_once( "$IP/extensions/MwEmbedSupport/MwEmbedSupport.php" );
require_once( "$IP/extensions/TimedMediaHandler/TimedMediaHandler.php" );
require_once( "$IP/extensions/PdfHandler/PdfHandler.php" );

// Configuration
$wgSearchType = 'CirrusSearch';
$wgOggThumbLocation = '/usr/bin/oggThumb';
$wgGroupPermissions[ '*' ][ 'deleterevision' ] = true;
$wgFileExtensions[] = 'pdf';
$wgCapitalLinks = false;
$wgUseInstantCommons = true;
$wgEnableUploads = true;
$wgJobTypeConf['default'] = array(
	'class' => 'JobQueueRedis',
	'order' => 'fifo',
	'redisServer' => 'localhost',
	'checkDelay' => true, # The magic bit.
	'redisConfig' => array(
		'password' => $wgRedisPassword,
	),
);
$wgJobQueueAggregator = array(
	'class'       => 'JobQueueAggregatorRedis',
	'redisServer' => 'localhost',
	'redisConfig' => array(
		'password' => $wgRedisPassword,
	),
);

// Running a ton of jobs every request helps to make sure all the pages that are created
// are indexed as fast as possible.
$wgJobRunRate = 100;

// Extra helpful configuration but not really required
$wgShowExceptionDetails = true;
$wgCirrusSearchShowScore = true;

class Jenkins {
	/**
	 * Installs a maintenance script the provides a clean Elasticsearch index for testing.
	 * @param DatabaseUpdater $updater database updater
	 * @return bool true so we let other extensions install more maintenance actions
	 */
	public static function installCleanSetup( $updater ) {
		$updater->addPostDatabaseUpdateMaintenance( 'CirrusSearch\Jenkins\CleanSetup');
		return true;
	}
}
