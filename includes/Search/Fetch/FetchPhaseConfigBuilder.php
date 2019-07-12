<?php

namespace CirrusSearch\Search\Fetch;

use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use Elastica\Query\AbstractQuery;

/**
 * Class holding the building state of the fetch phase elements of
 * an elasticsearch query.
 * Currently only supports the highlight section but can be extended to support
 * source filtering and stored field.
 */
class FetchPhaseConfigBuilder {
	/** @var BaseHighlightedFieldBuilder[] */
	private $highlightedFields = [];

	/** @var SearchConfig $config */
	private $config;

	/**
	 * @var string $factoryGroup
	 */
	private $factoryGroup;

	/**
	 * FetchPhaseConfigBuilder constructor.
	 * @param SearchConfig $config
	 * @param string|null $factoryGroup
	 */
	public function __construct( SearchConfig $config, $factoryGroup = null ) {
		$this->config = $config;
		$this->factoryGroup = $factoryGroup;
	}

	/**
	 * @param string $name
	 * @param string $target
	 * @param int $priority
	 * @return BaseHighlightedFieldBuilder
	 */
	public function newHighlightField(
		$name,
		$target,
		$priority = FetchedFieldBuilder::DEFAULT_TARGET_PRIORITY
	): BaseHighlightedFieldBuilder {
		$useExp = $this->config->get( 'CirrusSearchUseExperimentalHighlighter' );
		if ( $useExp ) {
			$factories = ExperimentalHighlightedFieldBuilder::getFactories();
		} else {
			$factories = BaseHighlightedFieldBuilder::getFactories();
		}
		if ( $this->factoryGroup !== null && isset( $factories[$this->factoryGroup][$name] ) ) {
			return ( $factories[$this->factoryGroup][$name] )( $this->config, $name, $target, $priority );
		}
		if ( $useExp ) {
			return new ExperimentalHighlightedFieldBuilder( $name, $target, $priority );
		} else {
			return new BaseHighlightedFieldBuilder( $name, BaseHighlightedFieldBuilder::FVH_HL_TYPE, $target, $priority );
		}
	}

	/**
	 * @param string $name
	 * @param string $target
	 * @param string $pattern
	 * @param bool $caseInsensitive
	 * @param int $priority
	 */
	public function addNewRegexHLField(
		$name,
		$target,
		$pattern,
		$caseInsensitive,
		$priority = FetchedFieldBuilder::COSTLY_EXPERT_SYNTAX_PRIORITY
	) {
		if ( !$this->config->get( 'CirrusSearchUseExperimentalHighlighter' ) ) {
			return;
		}
		$this->addHLField( ExperimentalHighlightedFieldBuilder::newRegexField(
			$this->config, $name, $target, $pattern, $caseInsensitive, $priority ) );
	}

	/**
	 * @param BaseHighlightedFieldBuilder $field
	 */
	public function addHLField( BaseHighlightedFieldBuilder $field ) {
		$prev = $this->highlightedFields[$field->getFieldName()] ?? null;
		if ( $prev === null ) {
			$this->highlightedFields[$field->getFieldName()] = $field;
		} else {
			$this->highlightedFields[$field->getFieldName()] = $prev->merge( $field );
		}
	}

	/**
	 * @param string $field
	 * @return BaseHighlightedFieldBuilder|null
	 */
	public function getHLField( $field ) {
		return $this->highlightedFields[$field] ?? null;
	}

	/**
	 * @param AbstractQuery|null $mainHLQuery
	 * @return array
	 */
	public function buildHLConfig( AbstractQuery $mainHLQuery = null ): array {
		$fields = [];
		foreach ( $this->highlightedFields as $field ) {
			$fields[$field->getFieldName()] = $field->toArray();
		}
		$config = [
			'pre_tags' => [ Searcher::HIGHLIGHT_PRE_MARKER ],
			'post_tags' => [ Searcher::HIGHLIGHT_POST_MARKER ],
			'fields' => $fields,
		];

		if ( $mainHLQuery !== null ) {
			$config['highlight_query'] = $mainHLQuery->toArray();
		}

		return $config;
	}

	/**
	 * @param SearchConfig $config
	 * @return FetchPhaseConfigBuilder
	 */
	public function withConfig( SearchConfig $config ): self {
		return new self( $config, $this->factoryGroup );
	}
}
