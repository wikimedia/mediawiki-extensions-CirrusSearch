<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\ElasticaErrorHandler;
use CirrusSearch\Maintenance\Printer;
use Elastica\Exception\ExceptionInterface;
use Elastica\Index;
use Elastica\Mapping;
use MediaWiki\Language\RawMessage;
use MediaWiki\Status\Status;
use Wikimedia\Assert\Assert;

class MappingValidator extends Validator {
	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var string
	 */
	private $masterTimeout;

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
	 * @todo this constructor takes way too much arguments - refactor
	 *
	 * @param Index $index
	 * @param string $masterTimeout
	 * @param bool $optimizeIndexForExperimentalHighlighter
	 * @param array $availablePlugins
	 * @param array $mappingConfig
	 * @param Printer|null $out
	 */
	public function __construct(
		Index $index,
		$masterTimeout,
		$optimizeIndexForExperimentalHighlighter,
		array $availablePlugins,
		array $mappingConfig,
		?Printer $out = null
	) {
		parent::__construct( $out );

		$this->index = $index;
		$this->masterTimeout = $masterTimeout;
		$this->optimizeIndexForExperimentalHighlighter = $optimizeIndexForExperimentalHighlighter;
		$this->availablePlugins = $availablePlugins;
		// Could be supported, but prefer consistency
		Assert::parameter( isset( $mappingConfig['properties'] ), '$mappingConfig',
			'Mapping types are no longer supported, properties must be top level' );
		$this->mappingConfig = $mappingConfig;
	}

	private function isNaturalSortConfigured() {
		// awkward much?
		return isset( $this->mappingConfig['properties']['title']['fields']['natural_sort'] );
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->outputIndented( "Validating mappings..." );
		if ( $this->optimizeIndexForExperimentalHighlighter &&
			!in_array( 'experimental-highlighter', $this->availablePlugins ) ) {
			$this->output( "impossible!\n" );
			return Status::newFatal( new RawMessage(
				"wgCirrusSearchOptimizeIndexForExperimentalHighlighter is set to true but the " .
				"'experimental-highlighter' plugin is not installed on all hosts." ) );
		}
		if ( $this->isNaturalSortConfigured() &&
			!in_array( 'analysis-icu', $this->availablePlugins ) ) {
			$this->output( "impossible!\n" );
			return Status::newFatal( new RawMessage(
				"wgCirrusSearchNaturalTitleSort is set to build but the " .
				"'analysis-icu' plugin is not installed on all hosts." ) );
		}

		if ( !$this->compareMappingToActual() ) {
			$action = new Mapping( $this->mappingConfig['properties'] );
			$action->setParam( "dynamic", false );

			try {
				$action->send( $this->index, [
					'master_timeout' => $this->masterTimeout,
				] );
				$this->output( "corrected\n" );
			} catch ( ExceptionInterface $e ) {
				$this->output( "failed!\n" );
				$message = ElasticaErrorHandler::extractMessage( $e );
				return Status::newFatal( new RawMessage(
					"Couldn't update existing mappings. You may need to reindex.\nHere is elasticsearch's error message: $message\n" ) );
			}
		}

		return Status::newGood();
	}

	/**
	 * Check that the mapping returned from Elasticsearch is as we want it.
	 *
	 * @return bool is the mapping good enough for us?
	 */
	private function compareMappingToActual() {
		$actualMappings = $this->index->getMapping();
		$this->output( "\n" );
		$this->outputIndented( "\tValidating mapping..." );
		if ( $this->checkConfig( $actualMappings, $this->mappingConfig ) ) {
			$this->output( "ok\n" );
			return true;
		} else {
			$this->output( "different..." );
			return false;
		}
	}
}
