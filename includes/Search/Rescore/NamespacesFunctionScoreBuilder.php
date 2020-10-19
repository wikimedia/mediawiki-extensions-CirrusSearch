<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\SearchConfig;
use Elastica\Query\FunctionScore;
use MediaWiki\MediaWikiServices;
use NamespaceInfo;

/**
 * Builds a set of functions with namespaces.
 * Uses a weight function with a filter for each namespace.
 * Activated only if more than one namespace is requested.
 */
class NamespacesFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var null|float[] initialized version of $wgCirrusSearchNamespaceWeights with all string keys
	 * translated into integer namespace codes using $this->language.
	 */
	private $normalizedNamespaceWeights;

	/**
	 * @var int[] List of namespace id's
	 */
	private $namespacesToBoost;

	/**
	 * @var NamespaceInfo
	 */
	private $namespaceInfo;

	/**
	 * @param SearchConfig $config
	 * @param int[]|null $namespaces
	 * @param float $weight
	 * @param NamespaceInfo|null $namespaceInfo
	 */
	public function __construct( SearchConfig $config, $namespaces, $weight, NamespaceInfo $namespaceInfo = null ) {
		parent::__construct( $config, $weight );

		$this->namespaceInfo = $namespaceInfo ?: MediaWikiServices::getInstance()->getNamespaceInfo();
		$this->namespacesToBoost =
			$namespaces ?: $this->namespaceInfo->getValidNamespaces();
		if ( !$this->namespacesToBoost || count( $this->namespacesToBoost ) == 1 ) {
			// nothing to boost, no need to initialize anything else.
			return;
		}
		$this->normalizedNamespaceWeights = [];
		$language = $config->get( 'ContLang' );
		foreach ( $config->get( 'CirrusSearchNamespaceWeights' ) as $ns =>
				  $weight
		) {
			if ( is_string( $ns ) ) {
				$ns = $language->getNsIndex( $ns );
				// Ignore namespaces that don't exist.
				if ( $ns === false ) {
					continue;
				}
			}
			// Now $ns should always be an integer.
			$this->normalizedNamespaceWeights[$ns] = $weight;
		}
	}

	/**
	 * Get the weight of a namespace.
	 *
	 * @param int $namespace
	 * @return float the weight of the namespace
	 */
	private function getBoostForNamespace( $namespace ) {
		if ( isset( $this->normalizedNamespaceWeights[$namespace] ) ) {
			return $this->normalizedNamespaceWeights[$namespace];
		}
		if ( $this->namespaceInfo->isSubject( $namespace ) ) {
			if ( $namespace === NS_MAIN ) {
				return 1;
			}

			return $this->config->get( 'CirrusSearchDefaultNamespaceWeight' );
		}
		$subjectNs = $this->namespaceInfo->getSubject( $namespace );
		if ( isset( $this->normalizedNamespaceWeights[$subjectNs] ) ) {
			return $this->config->get( 'CirrusSearchTalkNamespaceWeight' ) *
				   $this->normalizedNamespaceWeights[$subjectNs];
		}
		if ( $namespace === NS_TALK ) {
			return $this->config->get( 'CirrusSearchTalkNamespaceWeight' );
		}

		return $this->config->get( 'CirrusSearchDefaultNamespaceWeight' ) *
			   $this->config->get( 'CirrusSearchTalkNamespaceWeight' );
	}

	public function append( FunctionScore $functionScore ) {
		if ( !$this->namespacesToBoost || count( $this->namespacesToBoost ) == 1 ) {
			// nothing to boost, no need to initialize anything else.
			return;
		}

		// first build the opposite map, this will allow us to add a
		// single factor function per weight by using a terms filter.
		$weightToNs = [];
		foreach ( $this->namespacesToBoost as $ns ) {
			$weight = $this->getBoostForNamespace( $ns ) * $this->weight;
			$key = (string)$weight;
			if ( $key == '1' ) {
				// such weights would have no effect
				// we can ignore them.
				continue;
			}
			if ( !isset( $weightToNs[$key] ) ) {
				$weightToNs[$key] = [
					'weight' => $weight,
					'ns' => [ $ns ]
				];
			} else {
				$weightToNs[$key]['ns'][] = $ns;
			}
		}
		foreach ( $weightToNs as $weight => $namespacesAndWeight ) {
			$filter = new \Elastica\Query\Terms( 'namespace', $namespacesAndWeight['ns'] );
			$functionScore->addWeightFunction( $namespacesAndWeight['weight'], $filter );
		}
	}
}
