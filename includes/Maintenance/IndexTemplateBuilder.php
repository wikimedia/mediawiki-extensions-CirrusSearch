<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use Elastica\IndexTemplate;
use Wikimedia\Assert\Assert;

class IndexTemplateBuilder {
	/**
	 * @var array
	 */
	private $templateDefinition;

	/**
	 * @var string
	 */
	private $templateName;

	/**
	 * @var string[]
	 */
	private $availablePlugins;

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var string
	 */
	private $languageCode;

	/**
	 * @param Connection $connection
	 * @param string $templateName
	 * @param array $templateDefinition
	 * @param string[] $availablePlugins
	 * @param string $languageCode
	 */
	public function __construct(
		Connection $connection,
		$templateName,
		array $templateDefinition,
		array $availablePlugins,
		$languageCode
	) {
		Assert::parameter( isset( $templateDefinition['mappings']['properties'] ), '$templateDefinition',
			'Mapping types are no longer supported, properties must be top level in mappings' );
		$this->connection = $connection;
		$this->templateName = $templateName;
		$this->templateDefinition = $templateDefinition;
		$this->availablePlugins = $availablePlugins;
		$this->languageCode = $languageCode;
	}

	/**
	 * @param Connection $connection
	 * @param array $templateDefinition
	 * @param string[] $availablePlugins
	 * @return IndexTemplateBuilder
	 * @throws \InvalidArgumentException
	 */
	public static function build(
		Connection $connection,
		array $templateDefinition,
		array $availablePlugins
	): IndexTemplateBuilder {
		$templateName = $templateDefinition['template_name'] ?? null;
		$langCode = $templateDefinition['language_code'] ?? 'int';
		if ( $templateName === null ) {
			throw new \InvalidArgumentException( "Missing template name in profile." );
		}
		unset( $templateDefinition['template_name'] );
		unset( $templateDefinition['language_code'] );
		return new self( $connection, $templateName, $templateDefinition, $availablePlugins, $langCode );
	}

	public function execute() {
		$indexTemplate = $this->createIndexTemplate();
		$analysisConfigBuilder = new AnalysisConfigBuilder( $this->languageCode, $this->availablePlugins, $this->getSearchConfig() );
		$filter = new AnalysisFilter();
		list( $analysis, $mappings ) = $filter->filterAnalysis( $analysisConfigBuilder->buildConfig(),
			$this->templateDefinition['mappings'], true );
		$templateDefinition = array_merge_recursive( $this->templateDefinition, [ 'settings' => [ 'analysis' => $analysis ] ] );
		$templateDefinition['mappings'] = $mappings;
		$response = $indexTemplate->create( $templateDefinition );
		if ( !$response->isOk() ) {
			$message = $response->getErrorMessage();
			if ( $message ) {
				$message = 'Received HTTP ' . $response->getStatus();
			}
			throw new \RuntimeException( "Cannot add template {$this->templateName}: $message" );
		}
	}

	/**
	 * @return string
	 */
	public function getTemplateName() {
		return $this->templateName;
	}

	private function getSearchConfig() {
		return $this->connection->getConfig();
	}

	//
	private function createIndexTemplate() {
		// Can go back to plain IndexTemplate when upgrading to Elastica 7
		return new class( $this->connection->getClient(), $this->templateName ) extends IndexTemplate {
			public function request( $method, $data = [], array $query = [] ) {
				$path = '_template/' . $this->getName();
				return $this->getClient()->request( $path, $method, $data, $query );
			}

			public function create( array $args = [] ) {
				return $this->request( \Elastica\Request::PUT, $args, [ 'include_type_name' => 'false' ] );
			}
		};
	}
}
