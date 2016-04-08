<?php

/**
 * Sets up decently fully features cirrus configuration that relies on some of
 * the stuff installed by MediaWiki-Vagrant.
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

require_once( "$IP/extensions/Elastica/Elastica.php" );


$wgSearchType = 'CirrusSearch';
$wgCirrusSearchUseExperimentalHighlighter = true;
$wgCirrusSearchOptimizeIndexForExperimentalHighlighter = true;
$wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] = array( 'build', 'use' );
$wgCirrusSearchWikimediaExtraPlugin[ 'safer' ] = array(
	'phrase' => array(
	)
);

$wgCirrusSearchQueryStringMaxDeterminizedStates = 500;
$wgCirrusSearchWikimediaExtraPlugin[ 'super_detect_noop' ] = true;
$wgCirrusSearchWikimediaExtraPlugin[ 'id_hash_mod_filter' ] = true;

$wgCirrusSearchUseCompletionSuggester = 'yes';

$wgJobQueueAggregator = array(
	'class'       => 'JobQueueAggregatorRedis',
	'redisServer' => 'localhost',
	'redisConfig' => array(
		'password' => null,
	),
);

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
	// Limit users to a single concurent search. Note that this also effects
	// ip address, so shared ip addresses will run into issues.
	$wgPoolCounterConf[ 'CirrusSearch-PerUser' ] = array(
		'class' => 'PoolCounter_Client',
		'timeout' => 0,
		'workers' => 5,
		'maxqueue' => 5,
	);
}
