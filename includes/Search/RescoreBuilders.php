<?php

namespace CirrusSearch\Search;

use CirrusSearch\Util;
use Elastica\Query\FunctionScore;
use Elastica\Filter\AbstractFilter;
use MWNamespace;



/**
 * Set of rescore builders
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

/**
 * Builds a rescore queries by reading a rescore profile.
 */
class RescoreBuilder {
	/**
	 * List of allowed rescore params
	 * @todo: refactor to const with php 5.6
	 */
	private static $rescoreMainParams = array(
		'query_weight',
		'rescore_query_weight',
		'score_mode'
	);

	const FUNCTION_SCORE_TYPE = "function_score";

	/**
	 * @var SearchContext
	 */
	private $context;

	/**
	 * @var array a rescore profile
	 */
	private $profile;

	public function __construct( SearchContext $context, $profile ) {
		$this->context = $context;
		$this->profile = $this->getSupportedProfile( $profile );
	}

	/**
	 * @return array of rescore queries
	 */
	public function build() {
		$rescores = array();
		foreach( $this->profile['rescore'] as $rescoreDef ) {
			$windowSize = $this->windowSize( $rescoreDef );
			$rescore = array(
				'window_size' => $windowSize,
			);

			$rescore['query'] = array_intersect_key( $rescoreDef, array_flip( self::$rescoreMainParams ) );
			$rescoreQuery = $this->buildRescoreQuery( $rescoreDef );
			if ( $rescoreQuery === null ) {
				continue;
			}
			$rescore['query']['rescore_query'] = $rescoreQuery;
			$rescores[] = $rescore;
		}
		return $rescores;
	}

	/**
	 * builds the 'query' attribute by reading type
	 * @return array the rescore query
	 */
	private function buildRescoreQuery( $rescoreDef ) {
		switch( $rescoreDef['type'] ) {
		case self::FUNCTION_SCORE_TYPE:
			$funcChain = new FunctionScoreChain( $this->context, $rescoreDef['function_chain'] );
			return $funcChain->buildRescoreQuery();
		default: throw new InvalidRescoreProfileException( "Unsupported rescore query type: " . $rescoreDef['type'] );
		}
	}

	/**
	 * @return integer the window size defined in the profile
	 * or the value from config if window_size_override is set.
	 */
	private function windowSize( $rescore ) {
		if ( isset( $rescore['window_size_override'] ) ) {
			$windowSize = $this->context->getConfig()->get( $rescore['window_size_override'] );
			if ( $windowSize !== null ) {
				return $windowSize;
			}
		}
		return $rescore['window'];
	}

	/**
	 * Inspect requested namespaces and return the supported profile
	 * @return array the supported rescore profile.
	 */
	private function getSupportedProfile( $profile ) {
		if ( !is_array( $profile['supported_namespaces'] ) &&
			$profile['supported_namespaces'] === 'all' ) {
			return $profile;
		}

		if ( !is_array( $profile['supported_namespaces'] ) ) {
			throw new InvalidRescoreProfileException( "Invalid rescore profile: supported_namespaces should be 'all' or an array of namespaces" );
		}

		if ( ! isset( $profile['fallback_profile'] ) ) {
			throw new InvalidRescoreProfileException( "Invalid rescore profile: fallback_profile is mandatory if supported_namespaces is not 'all'." );
		}

		$queryNs = $this->context->getNamespaces();

		if ( !$queryNs ) {
			// According to comments in Searcher if namespaces is
			// not set we run the query on all namespaces
			// @todo: verify comments.
			return $this->getFallbackProfile( $profile['fallback_profile'] );
		}

		foreach( $queryNs as $ns ) {
			if ( !in_array( $ns, $profile['supported_namespaces'] ) ) {
				return $this->getFallbackProfile( $profile['fallback_profile'] );
			}
		}
		return $profile;
	}

	/**
	 * @param string $profileName the profile to load
	 * @return array the rescore profile identified by $profileName
	 */
	private function getFallbackProfile( $profileName ) {
		$profile = $this->context->getConfig()->getElement( 'CirrusSearchRescoreProfiles', $profileName );
		if ( !$profile ) {
			throw new InvalidRescoreProfileException( "Unknown fallback profile $profileName." );
		}
		if ( $profile['supported_namespaces'] !== 'all' ) {
			throw new InvalidRescoreProfileException( "Fallback profile $profileName must support all namespaces." );
		}
		return $profile;
	}
}

class FunctionScoreChain {
	/**
	 * @var SearchContext
	 */
	private $context;

	/**
	 * @var \Elastica\Query\FunctionScoreDecorator
	 */
	private $functionScore;

	/**
	 * @var array the function score chain
	 */
	private $chain;

	public function __construct( SearchContext $context, $chain ) {
		$this->context = $context;
		$this->functionScore = new FunctionScoreDecorator();
		$this->chain = $context->getConfig()->getElement( 'CirrusSearchRescoreFunctionScoreChains', $chain );
		if ( $this->chain === null ) {
			throw new InvalidRescoreProfileException( "Unknown rescore function chain $chain" );
		}
	}

	/**
	 * @return FunctionScore|null the rescore query or null none of functions were
	 * needed.
	 */
	public function buildRescoreQuery() {
		foreach( $this->chain as $func ) {
			$impl = $this->getImplementation( $func );
			$impl->append( $this->functionScore );
		}
		if ( !$this->functionScore->isEmptyFunction() ) {
			return $this->functionScore;
		}
		return null;
	}

	private function getImplementation( $func ) {
		switch( $func['type'] ) {
		case 'boostlinks':
			return new IncomingLinksFunctionScoreBuilder( $this->context );
		case 'recency':
			return new PreferRecentFunctionScoreBuilder( $this->context );
		case 'templates':
			return new BoostTemplatesFunctionScoreBuilder( $this->context );
		case 'namespaces':
			return new NamespacesFunctionScoreBuilder( $this->context );
		case 'language':
			return new LangWeightFunctionScoreBuilder( $this->context );
		case 'custom_field':
			return new CustomFieldFunctionScoreBuilder( $this->context, $func['params'] );
		default:
			throw new InvalidRescoreProfileException( "Unknown function score type {$func['type']}." );
		}
	}
}

/**
 * This is usefull to check if the function score is empty
 * Function score builders may not add any function if some
 * criteria are not met. If there's no function we should not
 * not build the rescore query.
 * @todo: find another pattern to deal with this problem and avoid
 * this strong dependency to FunctionScore::addFunction signature.
 */
class FunctionScoreDecorator extends FunctionScore {
	private $emptyFunction = true;

	public function addFunction( $functionType, $functionParams, AbstractFilter $filter = null, $weight = null ) {
		$this->emptyFunction = false;
		return parent::addFunction( $functionType, $functionParams, $filter, $weight );
	}

	/**
	 * @return boolean true if this function score is empty
	 */
	public function isEmptyFunction() {
		return $this->emptyFunction;
	}

	/**
	 * Default elastica behaviour is to use class name
	 * as property name. We must override this function
	 * to force the name to function_score
	 */
	protected function _getBaseName() {
		return "function_score";
	}
}

abstract class FunctionScoreBuilder {
	/**
	 * @param SearchContext $context
	 */
	protected $context;
	public function __construct( SearchContext $context ) {
		$this->context = $context;
	}

	/**
	 * Append functions to the function score $container
	 * @param FunctionScore $container
	 */
	public abstract function append( FunctionScore $container );
}

/**
 * Buils a set of functions with boosted templates
 * Uses a weight function with a filter for each template.
 * The list of boosted templates is read from SearchContext
 */
class BoostTemplatesFunctionScoreBuilder extends FunctionScoreBuilder {
	private $boostTemplates;

	/**
	 * @param SearchContext $context
	 */
	public function __construct( SearchContext $context ) {
		parent::__construct( $context );
		// Use the boosted template from query string if available
		$this->boostTemplates = $context->getBoostTemplatesFromQuery();
		// empty array may be returned here in the case of a syntax error
		// @todo: verify that this is what we want: in case of a syntax error
		// we disable default boost templates.
		if ( $this->boostTemplates === null ) {
			// Fallback to default otherwize
			$this->boostTemplates = Util::getDefaultBoostTemplates();
		}
	}

	public function append( FunctionScore $functionScore ) {
		if( !$this->boostTemplates ) {
			return;
		}
		foreach ( $this->boostTemplates as $name => $weight ) {
			$match = new \Elastica\Query\Match();
			$match->setFieldQuery( 'template', $name );
			$filterQuery = new \Elastica\Filter\Query( $match );
			$filterQuery->setCached( true );
			$functionScore->addWeightFunction( $weight, $filterQuery );
		}
	}
}

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
	private $namespaceToBoost;


	/**
	 * @param SearchContext $context
	 */
	public function __construct( SearchContext $context ) {
		parent::__construct( $context );
		$this->namespacesToBoost = $this->context->getNamespaces() ?: MWNamespace::getValidNamespaces();
		if ( !$this->namespacesToBoost || count( $this->namespacesToBoost ) == 1 ) {
			// nothing to boost, no need to initialize anything else.
			return;
		}
		$this->normalizedNamespaceWeights = array();
		$language = $this->context->getConfig()->get( 'ContLang' );
		foreach ( $this->context->getConfig()->get( 'CirrusSearchNamespaceWeights' ) as $ns => $weight ) {
			if ( is_string( $ns ) ) {
				$ns = $language->getNsIndex( $ns );
				// Ignore namespaces that don't exist.
				if ( $ns === false ) {
					continue;
				}
			}
			// Now $ns should always be an integer.
			$this->normalizedNamespaceWeights[ $ns ] = $weight;
		}

	}

	/**
	 * Get the weight of a namespace.
	 * @param int $namespace the namespace
	 * @return float the weight of the namespace
	 */
	private function getBoostForNamespace( $namespace ) {
		if ( isset( $this->normalizedNamespaceWeights[ $namespace ] ) ) {
			return $this->normalizedNamespaceWeights[ $namespace ];
		}
		if ( MWNamespace::isSubject( $namespace ) ) {
			if ( $namespace === NS_MAIN ) {
				return 1;
			}
			return $this->context->getConfig()->get( 'CirrusSearchDefaultNamespaceWeight' );
		}
		$subjectNs = MWNamespace::getSubject( $namespace );
		if ( isset( $this->normalizedNamespaceWeights[ $subjectNs ] ) ) {
			return $this->context->getConfig()->get( 'CirrusSearchTalkNamespaceWeight' ) * $this->normalizedNamespaceWeights[ $subjectNs ];
		}
		if ( $namespace === NS_TALK ) {
			return $this->context->getConfig()->get( 'CirrusSearchTalkNamespaceWeight' );
		}
		return $this->context->getConfig()->get( 'CirrusSearchDefaultNamespaceWeight' ) * $this->context->getConfig()->get( 'CirrusSearchTalkNamespaceWeight' );
	}

	public function append( FunctionScore $functionScore ) {
		if ( !$this->namespacesToBoost || count( $this->namespacesToBoost ) == 1 ) {
			// nothing to boost, no need to initialize anything else.
			return;
		}

		// first build the opposite map, this will allow us to add a
		// single factor function per weight by using a terms filter.
		$weightToNs = array();
		foreach( $this->namespacesToBoost as $ns ) {
			$weight = $this->getBoostForNamespace( $ns );
			$key = (string) $weight;
			if ( $key == '1' ) {
				// such weigths would have no effect
				// we can ignore them.
				continue;
			}
			if ( !isset( $weightToNs[$key] ) ) {
				$weightToNs[$key] = array( $ns );
			} else {
				$weightToNs[$key][] = $ns;
			}
		}
		foreach( $weightToNs as $weight => $namespaces ) {
			$filter = new \Elastica\Filter\Terms( 'namespace', $namespaces );
			$functionScore->addWeightFunction( $weight, $filter );
		}
	}
}

/**
 * Builds a function that boosts incoming links
 * formula is log( incoming_links + 2 )
 */
class IncomingLinksFunctionScoreBuilder extends FunctionScoreBuilder {
	public function __construct( SearchContext $context ) {
		parent::__construct( $context );
	}

	public function append( FunctionScore $functionScore ) {
		// Backward compat code, allows to disable this function
		// even if specified in the rescore profile
		if( !$this->context->isBoostLinks() ) {
			return;
		}
		if( $this->context->isUseFieldValueFactorWithDefault() ) {
			$functionScore->addFunction( 'field_value_factor_with_default', array(
				'field' => 'incoming_links',
				'modifier' => 'log2p',
				'missing' => 0,
			) );
		} else {
			$scoreBoostExpression = "log10(doc['incoming_links'].value + 2)";
			$functionScore->addScriptScoreFunction( new \Elastica\Script( $scoreBoostExpression, null, 'expression' ) );
		}
	}
}

/**
 * Builds a function using a custom numeric field and
 * parameters attached to a profile.
 * Uses the function field_value_factor
 */
class CustomFieldFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var array the field_value_factor profile
	 */
	private $profile;

	public function __construct( SearchContext $context, $profile ) {
		parent::__construct( $context );
		$this->profile = $profile;
	}

	public function append( FunctionScore $functionScore ) {
		$functionScore->addFunction( 'field_value_factor', $this->profile );
	}
}

/**
 * Builds a script score boost documents on the timestamp field.
 * Reads its param from SearchContext: preferRecentDecayPortion and preferRecentHalfLife
 * Can be initialized by config for full text and by special syntax in user query
 */
class PreferRecentFunctionScoreBuilder extends FunctionScoreBuilder {
	public function __construct( SearchContext $context ) {
		parent::__construct( $context );
	}

	public function append( FunctionScore $functionScore ) {
		if ( !$this->context->hasPreferRecentOptions() ) {
			return;
		}
		// Convert half life for time in days to decay constant for time in milliseconds.
		$decayConstant = log( 2 ) / $this->context->getPreferRecentHalfLife() / 86400000;
		$parameters = array(
			'decayConstant' => $decayConstant,
			'decayPortion' => $this->context->getPreferRecentDecayPortion(),
			'nonDecayPortion' => 1 - $this->context->getPreferRecentDecayPortion(),
			'now' => time() * 1000
		);

		// e^ct where t is last modified time - now which is negative
		$exponentialDecayExpression = "exp(decayConstant * (doc['timestamp'].value - now))";
		if ( $this->context->getPreferRecentDecayPortion() !== 1.0 ) {
			$exponentialDecayExpression = "$exponentialDecayExpression * decayPortion + nonDecayPortion";
		}
		$functionScore->addScriptScoreFunction( new \Elastica\Script( $exponentialDecayExpression,
			$parameters, 'expression' ) );
	}
}

/**
 * Boosts documents in user language and in wiki language if different
 * Uses getUserLanguage in SearchConfig and LanguageCode for language values
 * and CirrusSearchLanguageWeight['user'|'wiki'] for respective weights.
 */
class LangWeightFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var string user language
	 */
	private $userLang;
	/**
	 * @var float user language weight
	 */
	private $userWeight;
	/**
	 * @var string wiki language
	 */
	private $wikiLang;
	/**
	 * @var float wiki language weight
	 */
	private $wikiWeight;

	public function __construct( SearchContext $context ) {
		parent::__construct( $context );
		$this->userLang = $this->context->getConfig()->getUserLanguage();
		$this->userWeight = $this->context->getConfig()->getElement( 'CirrusSearchLanguageWeight', 'user' );
		$this->wikiLang = $this->context->getConfig()->get( 'LanguageCode' );
		$this->wikiWeight = $this->context->getConfig()->getElement( 'CirrusSearchLanguageWeight', 'wiki' );
	}

	public function append( FunctionScore $functionScore ) {
		// Boost pages in a user's language
		if ( $this->userWeight ) {
			$functionScore->addWeightFunction(
				$this->userWeight,
				new \Elastica\Filter\Term( array( 'language' => $this->userLang ) )
			);
		}

		// And a wiki's language, if it's different
		if ( $this->wikiWeight && $this->userLang != $this->wikiLang ) {
			$functionScore->addWeightFunction(
				$this->wikiWeight,
				new \Elastica\Filter\Term( array( 'language' => $this->wikiLang ) )
			);
		}
	}
}

/**
 * Exception thrown if an error has been detected in the rescore profiles
 */
class InvalidRescoreProfileException extends \Exception {
	public function __construct( $message ) {
		parent::__construct( $message );
	}
}
