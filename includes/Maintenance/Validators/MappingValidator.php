<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\Maintenance\Maintenance;
use Elastica\Exception\ExceptionInterface;
use Elastica\Index;
use Elastica\Type;
use Elastica\Type\Mapping;
use RawMessage;
use Status;

class MappingValidator extends Validator {
	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var bool
	 */
	private $optimizeIndexForExperimentalHighlighter;

	/**
	 * @var array
	 */
	private $availablePlugins;

	/**
	 * @var array
	 */
	private $mappingConfig;

	/**
	 * @var Type
	 */
	private $pageType;

	/**
	 * @var Type
	 */
	private $namespaceType;

	/**
	 * @todo: this constructor takes way too much arguments - refactor
	 *
	 * @param Index $index
	 * @param bool $optimizeIndexForExperimentalHighlighter
	 * @param array $availablePlugins
	 * @param array $mappingConfig
	 * @param Type $pageType
	 * @param Type $namespaceType
	 * @param Maintenance $out
	 */
	public function __construct( Index $index, $optimizeIndexForExperimentalHighlighter, array $availablePlugins, array $mappingConfig, Type $pageType, Type $namespaceType, Maintenance $out = null ) {
		parent::__construct( $out );

		$this->index = $index;
		$this->optimizeIndexForExperimentalHighlighter = $optimizeIndexForExperimentalHighlighter;
		$this->availablePlugins = $availablePlugins;
		$this->mappingConfig = $mappingConfig;
		$this->pageType = $pageType;
		$this->namespaceType = $namespaceType;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->outputIndented( "Validating mappings..." );
		if ( $this->optimizeIndexForExperimentalHighlighter &&
			!in_array( 'experimental highlighter', $this->availablePlugins ) ) {
			$this->output( "impossible!\n" );
			return Status::newFatal( new RawMessage(
				"wgCirrusSearchOptimizeIndexForExperimentalHighlighter is set to true but the " .
				"'experimental highlighter' plugin is not installed on all hosts." ) );
		}

		$requiredMappings = $this->mappingConfig;
		if ( !$this->checkMapping( $requiredMappings ) ) {
			// TODO Conflict resolution here might leave old portions of mappings
			$pageAction = new Mapping( $this->pageType );
			foreach ( $requiredMappings[ 'page' ] as $key => $value ) {
				$pageAction->setParam( $key, $value );
			}
			$namespaceAction = new Mapping( $this->namespaceType );
			foreach ( $requiredMappings[ 'namespace' ] as $key => $value ) {
				$namespaceAction->setParam( $key, $value );
			}
			try {
				$pageAction->send();
				$namespaceAction->send();
				$this->output( "corrected\n" );
			} catch ( ExceptionInterface $e ) {
				$this->output( "failed!\n" );
				$message = ElasticsearchIntermediary::extractMessage( $e );
				return Status::newFatal( new RawMessage(
					"Couldn't update mappings.  Here is elasticsearch's error message: $message\n" ) );
			}
		}

		return Status::newGood();
	}

	/**
	 * Check that the mapping returned from Elasticsearch is as we want it.
	 *
	 * @param array $requiredMappings the mappings we want
	 * @return bool is the mapping good enough for us?
	 */
	private function checkMapping( $requiredMappings ) {
		$actualMappings = $this->index->getMapping();
		$this->output( "\n" );
		$this->outputIndented( "\tValidating mapping..." );
		if ( $this->checkConfig( $actualMappings, $requiredMappings ) ) {
			$this->output( "ok\n" );
			return true;
		} else {
			$this->output( "different..." );
			return false;
		}
	}
}
