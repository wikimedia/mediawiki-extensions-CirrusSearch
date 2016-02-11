<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use Elastica\Client;
use RawMessage;
use Status;

abstract class IndexAliasValidator extends Validator {
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
				} elseif ( $this->shouldRemoveFromAlias( $index->getName() ) ) {
					$remove[] = $index->getName();
				}
			}

			$add[] = $this->specificIndexName;
		}

		return $this->updateIndices( $add, $remove );
	}

	protected abstract function shouldRemoveFromAlias( $name );

	/**
	 * @param string[] $add Array of indices to add
	 * @param string[] $remove Array of indices to remove
	 * @return Status
	 */
	protected function updateIndices( array $add, array $remove ) {
		$remove = array_filter( $remove, function ( $name ) {
			return $this->client->getIndex( $name )->exists();
		} );
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
