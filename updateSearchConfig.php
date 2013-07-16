<?php
/**
 * Update the search configuration on the search backend.
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
require_once( "maintenance/Maintenance.php" );

/**
 * Update the elasticsearch configuration for this index.
 */
class UpdateElasticsearchIndex extends Maintenance {
	private $rebuild, $closeOk;

	// Is the index currently closed?
	private $closed = false;
	// Is a reindex required
	private $reindexRequired = false;

	private $reindexChunkSize = 1000;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Update the elasticsearch index for this wiki";
		$this->addOption( 'rebuild', 'Rebuild the index.' );
		$this->addOption( 'forceOpen', "Open the index but do nothing else.  Use this if " .
			"you've stuck the index closed and need it to start working right now." );
		$this->addOption( 'closeOk', "Allow the script to close the index if decides it has " .
			"to.  Note that it is never ok to close an index that you just created." );
		$this->addOption( 'forceReindex', "Perform a reindex right now." );
	}
	public function execute() {
		// TODO support http://www.elasticsearch.org/blog/changing-mapping-with-zero-downtime/
		if ( $this->getOption( 'forceOpen', false) ) {
			CirrusSearch::getIndex()->open();
			return;
		}
		if ( $this->getOption( 'forceReindex', false) ) {
			$this->reindex();
			return;
		}
		$this->rebuild = $this->getOption( 'rebuild', false );
		$this->closeOk = $this->getOption( 'closeOk', false );

		$this->validateIndex();
		$this->validateAnalyzers();
		$this->validateMapping();

		if ($this->closed) {
			CirrusSearch::getIndex()->open();
		}
	}

	private function validateIndex() {
		if ( $this->rebuild ) {
			$this->output( "Rebuilding index..." );
			$this->createIndex( true );
			$this->output( "ok\n" );
			return;
		}
		if ( !CirrusSearch::getIndex()->exists() ) {
			$this->output( "Creating index..." );
			$this->createIndex( false );
			$this->output( "ok\n" );
			return;
		}
		$this->output( "Index exists so validating...\n" );
		global $wgCirrusSearchShardCount, $wgCirrusSearchReplicaCount;
		$settings = CirrusSearch::getIndex()->getSettings()->get();

		$this->output( "\tValidating number of shards..." );
		$actualShardCount = $settings['index.number_of_shards'];
		if ( $actualShardCount == $wgCirrusSearchShardCount ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualShardCount but should be $wgCirrusSearchShardCount...cannot correct!\n" );
			$this->error(
				"Number of shards is incorrect. You can run this script with --rebuild to\n" .
				"correct the problem but it will blow away the index and you'll have to reindex\n" .
				"the contents.  This script will now continue to validate everything else." );
		}

		$this->output( "\tValidating number of replicas..." );
		$actualReplicaCount = $settings['index.number_of_replicas'];
		if ( $actualReplicaCount == $wgCirrusSearchReplicaCount ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualReplicaCount but should be $wgCirrusSearchReplicaCount..." );
			CirrusSearch::getIndex()->getSettings()->setNumberOfReplicas( $wgCirrusSearchReplicaCount );
			$this->output( "corrected\n" );
		}
	}

	private function validateAnalyzers() {
		function vaActualMatchRequired( $prefix, $settings, $required ) {
			foreach( $required as $key => $value ) {
				$settingsKey = $prefix . '.' . $key;
				if ( is_array( $value ) ) {
					if ( !vaActualMatchRequired( $settingsKey, $settings, $value ) ) {
						return false;
					}
					continue;
				}
				// Note that I really mean !=, not !==.  Coercion is cool here.
				if ( !array_key_exists( $settingsKey, $settings ) || $settings[ $settingsKey ] != $value ) {
					return false;
				}
			}
			return true;
		}

		$this->output( "Validating analyzers..." );
		$settings = CirrusSearch::getIndex()->getSettings()->get();
		$requiredAnalyzers = $this->buildAnalyzers();
		if ( vaActualMatchRequired( 'index', $settings, $requiredAnalyzers ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "different..." );
			$this->closeAndCorrect( function() use ($requiredAnalyzers) {
				// TODO force the reindex.
				// TODO do not make changes unless the user oks reindexing.
				CirrusSearch::getIndex()->getSettings()->set( $requiredAnalyzers );
			} );
		}
	}

	private function validateMapping() {
		function vmActualMatchRequired( $actual, $required ) {
			foreach( $required as $key => $value ) {
				if ( !array_key_exists( $key, $actual ) ) {
					return false;
				}
				if ( is_array( $value ) ) {
					if ( !is_array( $actual[ $key ] ) ) {
						return false;
					}
					if ( !vmActualMatchRequired( $actual[ $key ], $value ) ) {
						return false;
					}
					continue;
				}
				// Note that I really mean !=, not !==.  Coercion is cool here.
				if ( $actual[ $key ] != $value ) {
					return false;
				}
			}
			return true;
		}

		$this->output( "Validating mappings...\n" );
		$actualMappings = CirrusSearch::getIndex()->getMapping();
		$actualMappings = $actualMappings[ CirrusSearch::getIndex()->getName() ];

		$this->output( "\tValidating mapping for page type..." );
		$requiredPageMappings = $this->buildPageMappings();
		if ( array_key_exists( 'page', $actualMappings) && 
				vmActualMatchRequired( $actualMappings[ 'page' ][ 'properties' ], $requiredPageMappings ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "different..." );
			// TODO Conflict resolution here might leave old portions of mappings
			$action = new \Elastica\Type\Mapping( CirrusSearch::getPageType(), $requiredPageMappings );
			try {
				$action->send();
				$this->output( "corrected\n" );
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$this->output( "failed!\n" );
				$message = $e->getMessage();
				$this->error( "Couldn't update mappings.  Here is elasticsearch's error message: $message\n" );
			}
		}
	}

	/**
	 * Rebuild the index by pulling everything out of it and putting it back in.  This should be faster than
	 * reparsing everything.
	 */
	private function reindex() {
		// TODO forcing a reindex causes an infinite loop right now because of what looks like a bug in Elastica.
		global $wgCirrusSearchShardCount;

		$query = new Elastica\Query();
		$query->setFields( array( '_id', '_source' ) );

		$result = CirrusSearch::getPageType()->search( $query, array(
			'search_type' => 'scan',
			'scroll' => '10m',
			'size'=> $this->reindexChunkSize / $wgCirrusSearchShardCount
		) );

		while ( true ) {
			wfProfileIn( __method__ . '::receiveDocs' );
			$result = CirrusSearch::getIndex()->search( array(), array(
				'scroll_id' => $result->getResponse()->getScrollId(),
				'scroll' => '10m'
			) );
			wfProfileOut( __method__ . '::receiveDocs' );
			if ( !$result->count() ) {
				$this->output( "All done\n" );
				break;
			}
			$this->output( 'Reindexing ' . $result->count() . " documents\n" );
			wfProfileIn( __method__ . '::packageDocs' );
			$documents = array();
			while ( $result->current() ) {
				$documents[] = new \Elastica\Document( $result->current()->getId(), $result->current()->getSource() );
				$result->next();
			}
			wfProfileOut( __method__ . '::packageDocs' );
			wfProfileIn( __method__ . '::sendDocs' );
			try {
				$updateResult = CirrusSearch::getPageType()->addDocuments( $documents );
				wfDebugLog( 'CirrusSearch', 'Update completed in ' . $updateResult->getEngineTime() . ' (engine) millis' );
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				error_log( "CirrusSearch update failed caused by:  " . $e->getMessage() );
			}
			wfProfileOut( __method__ . '::sendDocs' );
		}
	}

	private function createIndex( $rebuild ) {
		global $wgCirrusSearchShardCount, $wgCirrusSearchReplicaCount;
		CirrusSearch::getIndex()->create( array(
			'settings' => array_merge( $this->buildAnalyzers(), array(
				'number_of_shards' => $wgCirrusSearchShardCount,
				'number_of_replicas' => $wgCirrusSearchReplicaCount
			) )
		), $rebuild );
		$this->closeOk = false;
	}

	private function buildAnalyzers() {
		return array(
			'analysis' => array(
				'analyzer' => array(
					'text' => $this->buildTextAnalyzer(),
					'suggest' => array_merge( $this->buildTextAnalyzer(), array(
						'filter' => array( 'suggest_shingle' )
					) )
				),
				'filter' => array(
					'suggest_shingle' => array(
						'type' => 'shingle',
						'min_shingle_size' => 2,
						'max_shingle_size' => 5
					)
				)
			)
		);
	}

	private function buildTextAnalyzer() {
		global $wgLanguageCode;
		switch ($wgLanguageCode) {
			case 'en': return array(
				'type' => 'english'
			);
		}
	}

	private function buildPageMappings() {
		return array(
			'title' => $this->buildFieldWithSuggest( 'title' ),
			'text' => $this->buildFieldWithSuggest( 'text' ),
			'category' => array( 'type' => 'string', 'analyzer' => 'text' ),
			'redirect' => array(
				'type' => 'object',
				'properties' => array(
					'title' => array( 'type' => 'string', 'analyzer' => 'text' )
				)
			)
		);
	}

	private function buildFieldWithSuggest( $name ) {
		return array(
			'type' => 'multi_field',
			'fields' => array(
				$name => array( 'type' => 'string', 'analyzer' => 'text' ),
				'suggest' => array( 'type' => 'string', 'analyzer' => 'suggest' )
			)
		);
	}

	private function closeAndCorrect( $callback ) {
		if ( $this->closeOk ) {
			CirrusSearch::getIndex()->close();
			$this->closed = true;
			$callback();
			$this->output( "corrected\n" );
		} else {
			$this->output( "cannot correct\n" );
			$this->error("This script encountered an index difference that requires that the index be\n" .
				"closed, modified, and then reopened.  To allow this script to close the index run it\n" .
				"with the --closeOk parameter and it'll close the index for the briefest possible time\n" .
				"Note that the index will be unusable while closed." );
		}
	}
}

$maintClass = "UpdateElasticsearchIndex";
require_once RUN_MAINTENANCE_IF_MAIN;
