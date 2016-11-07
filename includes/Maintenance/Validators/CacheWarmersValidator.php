<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Searcher;
use CirrusSearch\Util;
use Elastica;
use Elastica\Exception\ResponseException;
use Elastica\Type;
use RawMessage;
use Status;

class CacheWarmersValidator extends Validator {
	/**
	 * @var string
	 */
	private $indexType;

	/**
	 * @var Type
	 */
	private $pageType;

	/**
	 * @var string[]
	 */
	private $cacheWarmers;

	/**
	 * @param string $indexType
	 * @param Type $pageType
	 * @param array $cacheWarmers
	 * @param Maintenance $out
	 */
	public function __construct( $indexType, Type $pageType, array $cacheWarmers = [], Maintenance $out = null ) {
		parent::__construct( $out );

		$this->indexType = $indexType;
		$this->pageType = $pageType;
		$this->cacheWarmers = $cacheWarmers;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->outputIndented( "Validating cache warmers...\n" );

		$expectedWarmers = $this->buildExpectedWarmers();
		$actualWarmers = $this->fetchActualWarmers();

		$warmersToUpdate = $this->diff( $expectedWarmers, $actualWarmers );
		$warmersToDelete = array_diff_key( $actualWarmers, $expectedWarmers );

		$status = $this->updateWarmers( $warmersToUpdate );
		$status2 = $this->deleteWarmers( $warmersToDelete );

		$status->merge( $status2 );
		return $status;
	}

	/**
	 * @return array[] Set of elasticsearch queries suitable for individually
	 *  json encoding and sending to elasticsearch.
	 */
	private function buildExpectedWarmers() {
		$warmers = [];
		foreach ( $this->cacheWarmers as $search ) {
			$warmers[ $search ] = $this->buildWarmer( $search );
		}

		return $warmers;
	}

	/**
	 * @param string $search
	 * @return array Elasticsearch query suitable for json encoding and sending
	 *  to elasticsearch.
	 */
	private function buildWarmer( $search ) {
		// This has a couple of compromises:
		$searcher = new Searcher(
			$this->maint->getConnection(),
			0, 50,
			// 0 offset 50 limit is the default for searching so we try it too.
			$this->maint->getSearchConfig(),
			[],
			// array() for namespaces will stop us from eagerly caching the namespace
			// filters. That is probably OK because most searches don't use one.
			// It'd be overeager.
			null
			// Null user because we won't be logging anything about the user.
		);
		$searcher->setReturnQuery( true );
		$searcher->setResultsType( new FullTextResultsType( FullTextResultsType::HIGHLIGHT_ALL ) );
		$searcher->limitSearchToLocalWiki( true );
		$query = $searcher->searchText( $search, true )->getValue();
		return $query[ 'query' ];
	}

	/**
	 * @return array[]
	 */
	private function fetchActualWarmers() {
		$data = $this->pageType->getIndex()->request( "_warmer/", 'GET' )->getData();
		$firstKeys = array_keys( $data );
		if ( count( $firstKeys ) === 0 ) {
			return [];
		}
		if ( !isset( $data[ $firstKeys[ 0 ] ][ 'warmers' ] ) ) {
			return [];
		}
		$warmers = $data[ $firstKeys[ 0 ] ][ 'warmers' ];
		foreach ( $warmers as &$warmer ) {
			// The 'types' field is funky - we can't send it back so we really just pretend it
			// doesn't exist.
			$warmer = $warmer[ 'source' ];
			unset( $warmer[ 'types' ] );
		}
		return $warmers;
	}

	/**
	 * @param array[] $warmers
	 * @return Status
	 */
	private function updateWarmers( $warmers ) {
		$type = $this->pageType->getName();
		foreach ( $warmers as $name => $contents ) {
			// The types field comes back on warmers but it can't be sent back in
			$this->outputIndented( "\tUpdating $name..." );
			$name = urlencode( $name );
			$path = "$type/_warmer/$name";
			try {
				$this->pageType->getIndex()->request( $path, 'PUT', $contents );
			} catch ( ResponseException $e ) {
				if ( preg_match( '/dynamic scripting for \\[.*\\] disabled/', $e->getResponse()->getError() ) ) {
					$this->output( "couldn't create dynamic script!\n" );
					return Status::newFatal( new RawMessage(
						"Couldn't create the dynamic script required for Cirrus to work properly.  " .
						"For now, Cirrus requires dynamic scripting.  It'll switch to sandboxed Groovy when it " .
						"updates to support Elasticsearch 1.3.1 we promise.  For now enable dynamic scripting and " .
						"keep Elasticsearch safely not accessible to people you don't trust.  You should always " .
						"do that, but especially when dynamic scripting is enabled." ) );
				}
			}
			$this->output( "done\n" );
		}

		return Status::newGood();
	}

	/**
	 * @param array[] $warmers
	 * @return Status
	 */
	private function deleteWarmers( $warmers ) {
		foreach ( array_keys( $warmers ) as $name ) {
			$this->outputIndented( "\tDeleting $name..." );
			$name = urlencode( $name );
			$path = "_warmer/$name";
			$this->pageType->getIndex()->request( $path, 'DELETE' );
			$this->output( "done\n" );
		}

		return Status::newGood();
	}

	/**
	 * @param array[] $expectedWarmers
	 * @param array[] $actualWarmers
	 * @return array[] List of warmers that differ
	 */
	private function diff( $expectedWarmers, $actualWarmers ) {
		$result = [];
		foreach ( $expectedWarmers as $key => $value ) {
			if ( !isset( $actualWarmers[ $key ] ) || !Util::recursiveSame( $value, $actualWarmers[ $key ] ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}
}
