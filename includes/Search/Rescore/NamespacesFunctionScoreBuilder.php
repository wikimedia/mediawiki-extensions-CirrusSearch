<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\Search\SearchContext;
use Elastica\Query\FunctionScore;
use MWNamespace;

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
	 * @param SearchContext $context
	 * @param float $weight
	 */
	public function __construct( SearchContext $context, $weight ) {
		parent::__construct( $context, $weight );
		$this->namespacesToBoost =
			$this->context->getNamespaces() ?: MWNamespace::getValidNamespaces();
		if ( !$this->namespacesToBoost || count( $this->namespacesToBoost ) == 1 ) {
			// nothing to boost, no need to initialize anything else.
			return;
		}
		$this->normalizedNamespaceWeights = [];
		$language = $this->context->getConfig()->get( 'ContLang' );
		foreach ( $this->context->getConfig()->get( 'CirrusSearchNamespaceWeights' ) as $ns =>
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
		if ( MWNamespace::isSubject( $namespace ) ) {
			if ( $namespace === NS_MAIN ) {
				return 1;
			}

			return $this->context->getConfig()->get( 'CirrusSearchDefaultNamespaceWeight' );
		}
		$subjectNs = MWNamespace::getSubject( $namespace );
		if ( isset( $this->normalizedNamespaceWeights[$subjectNs] ) ) {
			return $this->context->getConfig()->get( 'CirrusSearchTalkNamespaceWeight' ) *
				   $this->normalizedNamespaceWeights[$subjectNs];
		}
		if ( $namespace === NS_TALK ) {
			return $this->context->getConfig()->get( 'CirrusSearchTalkNamespaceWeight' );
		}

		return $this->context->getConfig()->get( 'CirrusSearchDefaultNamespaceWeight' ) *
			   $this->context->getConfig()->get( 'CirrusSearchTalkNamespaceWeight' );
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
