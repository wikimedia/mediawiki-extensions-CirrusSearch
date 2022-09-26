<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Elastica\SearchAfter;
use CirrusSearch\Maintenance\Exception\IndexDumperException;
use CirrusSearch\SearchConfig;
use Elastica;
use Elastica\JSON;
use Elastica\Query;

/**
 * Dump an index to stdout
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
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

/**
 * Dump an index from elasticsearch.
 */
class DumpIndex extends Maintenance {

	/**
	 * @var string
	 */
	private $indexSuffix;

	/**
	 * @var string
	 */
	private $indexBaseName;

	/**
	 * @var string
	 */
	private $indexIdentifier;

	/**
	 * @var int number of docs per shard we export
	 */
	private $inputChunkSize = 500;

	/**
	 * @var bool
	 */
	private $logToStderr = false;

	/**
	 * @var int
	 */
	private $lastProgressPrinted;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Dump an index into a 'json' based format stdout. " .
			"This format complies to the elasticsearch bulk format and can be directly used " .
			"with a curl command like : " .
			"curl -s -XPOST localhost:9200/{index}/_bulk --data-binary @dump-file\n" .
			"Note that you need to specify the index in the URL because the bulk commands do not " .
			"contain the index name. Beware that the bulk import is not meant to import very large " .
			"files, sweet spot seems to be between 2000 and 5000 documents (see examples below)." .
			"\nThis always operates on a single cluster." .
			"\n\nExamples :\n" .
			" - Dump a general index :" .
			"\n\tdumpIndex --indexSuffix general\n" .
			" - Dump a large content index into compressed chunks of 100000 documents :" .
			"\n\tdumpIndex --indexSuffix content | split -d -a 9 -l 100000  " .
			"--filter 'gzip -c > \$FILE.txt.gz' - \"\" \n" .
			"\nYou can import the data with the following commands :\n" .
			" - Import chunks of 2000 documents :" .
			"\n\tcat dump | split -l 4000 --filter 'curl -s http://elastic:9200/{indexName}/_bulk " .
			"--data-binary @- > /dev/null'\n" .
			" - Import 3 chunks of 2000 documents in parallel :" .
			"\n\tcat dump | parallel --pipe -L 2 -N 2000 -j3 'curl -s http://elastic:9200/{indexName}/_bulk " .
			"--data-binary @- > /dev/null'" );
		$this->addOption( 'indexSuffix', 'Index to dump. Either content or general.', false, true );
		$this->addOption( 'indexType', 'BC form of --indexSuffix.', false, true );
		$this->addOption( 'baseName', 'What basename to use, ' .
			'defaults to wiki id.', false, true );
		$this->addOption( 'filter', 'Dump only the documents that match the filter query ' .
			'(queryString syntax).', false, true );
		$this->addOption( 'limit', 'Maximum number of documents to dump, 0 means no limit. Defaults to 0.',
			false, true );
		$this->addOption( 'indexIdentifier', 'Force the index identifier, use the alias otherwise.', false, true );
		$this->addOption( 'sourceFields', 'List of comma separated source fields to extract.', false, true );
	}

	public function execute() {
		$this->disablePoolCountersAndLogging();

		$this->indexSuffix = $this->getBackCompatOption( 'indexSuffix', 'indexType' );
		$this->indexBaseName = $this->getOption( 'baseName',
			$this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ) );

		$indexSuffixes = $this->getConnection()->getAllIndexSuffixes();
		if ( !in_array( $this->indexSuffix, $indexSuffixes ) ) {
			$this->fatalError( 'indexSuffix option must be one of ' .
				implode( ', ', $indexSuffixes ) );
		}

		$this->indexIdentifier = $this->getOption( 'indexIdentifier' );

		$filter = null;
		if ( $this->hasOption( 'filter' ) ) {
			$filter = new Elastica\Query\QueryString( $this->getOption( 'filter' ) );
		}

		$limit = (int)$this->getOption( 'limit', 0 );

		$query = new Query();
		$query->setStoredFields( [ '_id', '_type', '_source' ] );
		$query->setSize( $this->inputChunkSize );
		// Elasticsearch docs (mapping-id-field.html) say that sorting by _id is restricted
		// from use, but at least on 7.10.2 this works as one would expect. The _id comes
		// from fielddata, this would be more efficient with a doc-values enabled field.
		// Additionally the cluster setting 'indices.id_field_data.enabled=false' breaks
		// this.
		$query->setSort( [
			[ '_id' => 'asc' ],
		] );
		$query->setTrackTotalHits( true );
		if ( $this->hasOption( 'sourceFields' ) ) {
			$sourceFields = explode( ',', $this->getOption( 'sourceFields' ) );
			$query->setSource( [ 'include' => $sourceFields ] );
		}
		if ( $filter ) {
			$bool = new \Elastica\Query\BoolQuery();
			$bool->addFilter( $filter );
			$query->setQuery( $bool );
		}

		$search = new \Elastica\Search( $this->getClient() );
		$search->setQuery( $query );
		$search->addIndex( $this->getIndex() );
		$searchAfter = new SearchAfter( $search );

		$totalDocsInIndex = -1;
		$totalDocsToDump = -1;
		$docsDumped = 0;

		$this->logToStderr = true;

		foreach ( $searchAfter as $results ) {
			if ( $totalDocsInIndex === -1 ) {
				$totalDocsInIndex = $this->getTotalHits( $results );
				$totalDocsToDump = $limit > 0 ? $limit : $totalDocsInIndex;
				$this->output( "Dumping $totalDocsToDump documents ($totalDocsInIndex in the index)\n" );
			}

			foreach ( $results as $result ) {
				$document = [
					'_id' => $result->getId(),
					'_type' => $result->getType(),
					'_source' => $result->getSource()
				];
				$this->write( $document );
				$docsDumped++;
				if ( $docsDumped >= $totalDocsToDump ) {
					break;
				}
			}
			$this->outputProgress( $docsDumped, $totalDocsToDump );
		}
		$this->output( "Dump done ($docsDumped docs).\n" );

		return true;
	}

	/**
	 * @param array $document Valid elasticsearch document to write to stdout
	 */
	public function write( array $document ) {
		$indexOp = [
			'index' => [
				'_type' => $document['_type'],
				'_id' => $document['_id']
			] ];

		// We use Elastica wrapper around json_encode.
		// Depending on PHP version JSON_ESCAPE_UNICODE will be used
		$this->writeLine( JSON::stringify( $indexOp ) );
		$this->writeLine( JSON::stringify( $document['_source'] ) );
	}

	/**
	 * @param string $data
	 */
	private function writeLine( $data ) {
		if ( !fwrite( STDOUT, $data . "\n" ) ) {
			throw new IndexDumperException( "Cannot write to standard output" );
		}
	}

	/**
	 * @return Elastica\Index being updated
	 */
	private function getIndex() {
		if ( $this->indexIdentifier ) {
			return $this->getConnection()->getIndex( $this->indexBaseName, $this->indexSuffix, $this->indexIdentifier );
		} else {
			return $this->getConnection()->getIndex( $this->indexBaseName, $this->indexSuffix );
		}
	}

	/**
	 * @param string $message
	 */
	public function outputIndented( $message ) {
		$this->output( "\t$message" );
	}

	/**
	 * @param string $message
	 * @param string|null $channel
	 */
	public function output( $message, $channel = null ) {
		if ( $this->mQuiet ) {
			return;
		}
		if ( $this->logToStderr ) {
			// We must log to stderr
			fwrite( STDERR, $message );
		} else {
			parent::output( $message );
		}
	}

	/**
	 * public because php 5.3 does not support accessing private
	 * methods in a closure.
	 * @param int $docsDumped
	 * @param int $limit
	 */
	public function outputProgress( $docsDumped, $limit ) {
		if ( $docsDumped <= 0 ) {
			return;
		}
		$pctDone = (int)( ( $docsDumped / $limit ) * 100 );
		if ( $this->lastProgressPrinted == $pctDone ) {
			return;
		}
		$this->lastProgressPrinted = $pctDone;
		if ( ( $pctDone % 2 ) == 0 ) {
			$this->outputIndented( "$pctDone% done...\n" );
		}
	}

	/**
	 * @return Elastica\Client
	 */
	protected function getClient() {
		return $this->getConnection()->getClient();
	}

	/**
	 * @param Elastica\ResultSet $results
	 * @return mixed|string
	 */
	private function getTotalHits( Elastica\ResultSet $results ) {
		// hack to support ES6, switch to getTotalHits
		return $results->getResponse()->getData()["hits"]["total"]["value"] ??
			   $results->getResponse()->getData()["hits"]["total"];
	}

}

$maintClass = DumpIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;
