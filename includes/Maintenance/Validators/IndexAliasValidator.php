<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use Elastica\Client;
use RawMessage;
use Status;

class IndexAliasValidator extends Validator {
	/**
	 * @var Client
	 */
	protected $client;

	/**
	 * @var string
	 */
	protected $aliasName;

	/**
	 * @var string
	 */
	protected $specificIndexName;

	/**
	 * @var bool
	 */
	private $startOver;

	/**
	 * @var array
	 */
	protected $create = array();

	/**
	 * @var array
	 */
	protected $remove = array();

	/**
	 * @param Client $client
	 * @param string $aliasName
	 * @param string $specificIndexName
	 * @param bool $startOver
	 * @param Maintenance $out
	 */
	public function __construct( Client $client, $aliasName, $specificIndexName, $startOver, Maintenance $out = null ) {
		parent::__construct( $out );

		$this->client = $client;
		$this->aliasName = $aliasName;
		$this->specificIndexName = $specificIndexName;
		$this->startOver = $startOver;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		// arrays of aliases to be added/removed
		$add = $remove = array();

		$this->outputIndented( "\tValidating $this->aliasName alias..." );
		$status = $this->client->getStatus();
		if ( $status->indexExists( $this->aliasName ) ) {
			$this->output( "is an index..." );
			if ( $this->startOver ) {
				$this->client->getIndex( $this->aliasName )->delete();
				$this->output( "index removed..." );

				$add[] = $this->specificIndexName;
			} else {
				$this->output( "cannot correct!\n" );
				return Status::newFatal( new RawMessage(
					"There is currently an index with the name of the alias.  Rerun this\n" .
					"script with --startOver and it'll remove the index and continue.\n" ) );
			}
		} else {
			foreach ( $status->getIndicesWithAlias( $this->aliasName ) as $index ) {
				if ( $index->getName() === $this->specificIndexName ) {
					$this->output( "ok\n" );
					return Status::newGood();
				} else {
					$remove[] = $index->getName();
				}
			}

			$add[] = $this->specificIndexName;
		}

		return $this->updateIndices( $add, $remove );
	}

	/**
	 * @param string[] $add Array of indices to add
	 * @param string[] $remove Array of indices to remove
	 * @return Status
	 */
	protected function updateIndices( array $add, array $remove ) {
		$data = array();

		$this->output( "alias not already assigned to this index..." );

		// We'll remove the all alias from the indices that we're about to delete while
		// we add it to this index.  Elastica doesn't support this well so we have to
		// build the request to Elasticsearch ourselves.

		foreach ( $add as $indexName ) {
			$data['action'][] = array( 'add' => array( 'index' => $indexName, 'alias' => $this->aliasName ) );
		}

		foreach ( $remove as $indexName ) {
			$data['action'][] = array( 'remove' => array( 'index' => $indexName, 'alias' => $this->aliasName ) );
		}

		$this->client->request( '_aliases', \Elastica\Request::POST, $data );
		$this->output( "corrected\n" );

		if ( $remove ) {
			$this->outputIndented( "\tRemoving old indices...\n" );
			foreach ( $remove as $indexName ) {
				$this->outputIndented( "\t\t$indexName..." );
				$this->client->getIndex( $indexName )->delete();
				$this->output( "done\n" );
			}
		}

		return Status::newGood();
	}
}
