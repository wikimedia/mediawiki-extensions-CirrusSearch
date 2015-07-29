<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch;
use CirrusSearch\Searcher;
use Status;

/**
 * Run search queries provided on stdin
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

$IP = getenv( 'MW_INSTALL_PATH' );
if( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );
require_once( __DIR__ . '/../includes/Maintenance/Maintenance.php' );

class RunSearch extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Run one or more searches against the cluster. " .
			"search queries are read from stdin." );
		$this->addOption( 'type', 'What type of search to run, prefix or full_text. ' .
			'defaults to full_text.', false, true );
		$this->addOption( 'options', 'A JSON object mapping from global variable to ' .
			'its test value' );
		$this->addOption( 'fork', 'Fork multiple processes to run queries from.' .
			'defaults to false.', false, true );
	}

	public function execute() {
		global $wgPoolCounterConf, $wgCirrusSearchLogElasticRequests;

		// Make sure we don't flood the pool counter
		unset( $wgPoolCounterConf['CirrusSearch-Search'],
			$wgPoolCounterConf['CirrusSearch-PerUser'] );

		// Don't skew the dashboards by logging these requests to
		// the global request log.
		$wgCirrusSearchLogElasticRequests = false;

		$this->applyGlobals();
		$callback = array( $this, 'consume' );
		$forks = $this->getOption( 'fork', false );
		$forks = ctype_digit( $forks ) ? intval( $forks ) : 0;
		$controller = new StreamingForkController( $forks, $callback, STDIN, STDOUT );
		$controller->start();
	}

	/**
	 * Applies global variables provided as the options CLI argument
	 * to override current settings.
	 */
	protected function applyGlobals() {
		$options = json_decode( $this->getOption( 'options', 'false' ) );
		if ( $options ) {
			foreach ( $options as $key => $value ) {
				if ( array_key_exists( $key, $GLOBALS ) ) {
					$GLOBALS[$key] = $value;
				} else {
					$this->error( "\nERROR: $key is not a valid global variable\n" );
					exit();
				}
			}
		}
	}

	/**
	 * Transform the search request into a JSON string representing the
	 * search result.
	 *
	 * @param string $query
	 * @return string JSON object
	 */
	public function consume( $query ) {
		$data = array( 'query' => $query );
		$status = $this->searchFor( $query );
		if ( $status->isOK() ) {
			$data['rows'] = $status->getValue()->numRows();
		} else {
			$data['error'] = $resultSet->getMessage()->text();
		}
		return json_encode( $data );
	}

	/**
	 * Transform the search request into a Status object representing the
	 * search result. Varies based on CLI input argument `type`.
	 *
	 * @param string $query
	 * @return Status<ResultSet>
	 */
	protected function searchFor( $query ) {
		$searchType = $this->getOption( 'type', 'full_text' );
		switch ( $searchType ) {
		case 'full_text':
			$engine = new CirrusSearch;
			$result = $engine->searchText( $query );
			if ( $result instanceof Status ) {
				return $result;
			} else {
				return Status::newGood( $result );
			}

		case 'prefix':
			$searcher = new Searcher( 0, 10 );
			return $searcher->prefixSearch( $query );

		default:
			$this->error( "\nERROR: Unknown search type $searchType\n" );
			exit( 1 );
		}
	}
}

$maintClass = "CirrusSearch\Maintenance\RunSearch";
require_once RUN_MAINTENANCE_IF_MAIN;
