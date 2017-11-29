<?php

namespace CirrusSearch\Search;

use CirrusSearch\Elastica\LtrQuery;
use CirrusSearch\Util;
use Elastica\Query\FunctionScore;
use Elastica\Query\AbstractQuery;
use Hooks;
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
	 * @var int Maximum number of rescore profile fallbacks
	 */
	const FALLBACK_LIMIT = 4;

	/**
	 * List of allowed rescore params
	 * @todo: refactor to const with php 5.6
	 *
	 * @var string[] $rescoreMainParams
	 */
	private static $rescoreMainParams = [
		'query_weight',
		'rescore_query_weight',
		'score_mode'
	];

	const FUNCTION_SCORE_TYPE = "function_score";
	const LTR_TYPE = "ltr";
	const PHRASE = "phrase";

	/**
	 * @var SearchContext
	 */
	private $context;

	/**
	 * @var array|string a rescore profile
	 */
	private $profile;

	/**
	 * @param SearchContext $context
	 * @param string|null $profile
	 */
	public function __construct( SearchContext $context, $profile = null ) {
		$this->context = $context;
		if ( $profile === null ) {
			$profile = $context->getRescoreProfile();
		}
		$this->profile = $this->getSupportedProfile( $profile );
	}

	/**
	 * @return array of rescore queries
	 */
	public function build() {
		$rescores = [];
		foreach ( $this->profile['rescore'] as $rescoreDef ) {
			$windowSize = $this->windowSize( $rescoreDef );
			if ( $windowSize <= 0 ) {
				continue;
			}
			$rescore = [
				'window_size' => $windowSize,
			];

			$rescore['query'] = $this->prepareQueryParams( $rescoreDef );
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
	 *
	 * @param array $rescoreDef
	 * @return AbstractQuery|null the rescore query
	 * @throws InvalidRescoreProfileException
	 */
	private function buildRescoreQuery( array $rescoreDef ) {
		switch ( $rescoreDef['type'] ) {
		case self::FUNCTION_SCORE_TYPE:
			$funcChain = new FunctionScoreChain( $this->context, $rescoreDef['function_chain'] );
			return $funcChain->buildRescoreQuery();
		case self::LTR_TYPE:
			return $this->buildLtrQuery( $rescoreDef['model'] );
		case self::PHRASE:
			return $this->context->getPhraseRescoreQuery();
		default:
			throw new InvalidRescoreProfileException( "Unsupported rescore query type: " . $rescoreDef['type'] );
		}
	}

	/**
	 * @param string $model Name of the sltr model to use
	 * @return AbstractQuery
	 */
	private function buildLtrQuery( $model ) {
		// This is a bit fragile, and makes the bold assumption
		// only a single level of rescore will be used. This is
		// strictly for debugging/testing before shipping a model
		// live so shouldn't be a big deal.
		$override = \RequestContext::getMain()
			->getRequest()
			->getVal( 'cirrusMLRModel' );
		if ( $override ) {
			$model = $override;
		}

		$bool = new \Elastica\Query\BoolQuery();
		// the ltr query can return negative scores, which mucks with elasticsearch
		// sorting as that will put these results below documents set to 0. Fix
		// that up by adding a large constant boost.
		$constant = new \Elastica\Query\ConstantScore( new \Elastica\Query\MatchAll );
		$constant->setBoost( 100000 );
		$bool->addShould( $constant );
		$bool->addShould( new LtrQuery( $model, [
				// TODO: These params probably shouldn't be hard coded
				'query_string' => $this->context->getCleanedSearchTerm(),
			] ) );

		return $bool;
	}

	/**
	 * @param array $rescore
	 * @return integer the window size defined in the profile
	 * or the value from config if window_size_override is set.
	 */
	private function windowSize( array $rescore ) {
		if ( isset( $rescore['window_size_override'] ) ) {
			$windowSize = $this->context->getConfig()->get( $rescore['window_size_override'] );
			if ( $windowSize !== null ) {
				return $windowSize;
			}
		}
		return $rescore['window'];
	}

	/**
	 * Assemble query params in the rescore block
	 * Only self::$rescoreMainParams are allowed.
	 * @param array $settings
	 * @return array
	 */
	private function prepareQueryParams( array $settings ) {
		$def = [];
		foreach ( self::$rescoreMainParams as $param ) {
			if ( !isset( $settings[$param] ) ) {
				continue;
			}
			$value = $settings[$param];
			if ( isset( $settings[$param . '_override'] ) ) {
				$oValue = $this->context->getConfig()->get( $settings[$param . '_override'] );
				if ( $oValue !== null ) {
					$value = $oValue;
				}
			}
			$def[$param] = $value;
		}
		return $def;
	}

	/**
	 * Inspect requested namespaces and return the supported profile
	 *
	 * @param string|array $profileName
	 * @return array the supported rescore profile.
	 * @throws InvalidRescoreProfileException
	 */
	private function getSupportedProfile( $profileName ) {
		if ( is_array( $profileName ) ) {
			$profile = $profileName;
			$profileName = '__provided__';
		} else {
			$profile = $this->context->getConfig()->getElement( 'CirrusSearchRescoreProfiles', $profileName );
			if ( !$profile ) {
				throw new InvalidRescoreProfileException( "Unknown fallback profile: $profileName" );
			} elseif ( !is_array( $profile ) ) {
				throw new InvalidRescoreProfileException( "Invalid fallback profile, must be array: $profileName" );
			}
		}

		$seen = [];
		while ( true ) {
			$seen[$profileName] = true;
			if ( count( $seen ) > self::FALLBACK_LIMIT ) {
				throw new InvalidRescoreProfileException(
					"Fell back more than " . self::FALLBACK_LIMIT . " times"
				);
			}

			if ( ! $this->isProfileNamespaceSupported( $profile )
				|| ! $this->isProfileSyntaxSupported( $profile )
			) {
				if ( ! isset( $profile['fallback_profile'] ) ) {
					throw new InvalidRescoreProfileException(
						"Invalid rescore profile: fallback_profile is mandatory "
						. "if supported_namespaces is not 'all' or "
						. "unsupported_syntax is not null."
					);
				}
				$profileName = $profile['fallback_profile'];
				if ( isset( $seen[$profileName] ) ) {
					$chain = implode( '->', $seen ) . "->$profileName";
					throw new InvalidRescoreProfileException( "Cycle in rescore fallbacks: $chain" );
				}

				$profile = $this->context->getConfig()->getElement( 'CirrusSearchRescoreProfiles', $profileName );
				if ( !$profile ) {
					throw new InvalidRescoreProfileException( "Unknown fallback profile: $profileName" );
				} elseif ( !is_array( $profile ) ) {
					throw new InvalidRescoreProfileException( "Invalid fallback profile, must be array: $profileName" );
				}
				continue;
			}
			return $profile;
		}
	}

	/**
	 * Check if a given profile supports the namespaces used by the current
	 * search request.
	 *
	 * @param array $profile Profile to check
	 * @return bool True is the profile supports current namespaces
	 */
	private function isProfileNamespaceSupported( array $profile ) {
		if ( !is_array( $profile['supported_namespaces'] ) ) {
			switch ( $profile['supported_namespaces'] ) {
			case 'all':
				return true;
			case 'content':
				$profileNs = $this->context->getConfig()->get( 'ContentNamespaces' );
				// Default search namespaces are also considered content
				$defaultSearch = $this->context->getConfig()->get( 'NamespacesToBeSearchedDefault' );
				foreach ( $defaultSearch as $ns => $isDefault ) {
					if ( $isDefault ) {
						$profileNs[] = $ns;
					}
				}
				break;
			default:
				throw new InvalidRescoreProfileException( "Invalid rescore profile: supported_namespaces should be 'all', 'content' or an array of namespaces" );
			}
		} else {
			$profileNs = $profile['supported_namespaces'];
		}

		$queryNs = $this->context->getNamespaces();

		if ( !$queryNs ) {
			// According to comments in Searcher if namespaces is
			// not set we run the query on all namespaces
			// @todo: verify comments.
			return false;
		}

		foreach ( $queryNs as $ns ) {
			if ( !in_array( $ns, $profileNs ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the given profile supports the syntax used by the
	 * current search request.
	 *
	 * @param array $profile
	 * @return bool
	 */
	private function isProfileSyntaxSupported( array $profile ) {
		if ( !isset( $profile['unsupported_syntax'] ) ) {
			return true;
		}

		foreach ( $profile['unsupported_syntax'] as $reject ) {
			if ( $this->context->isSyntaxUsed( $reject ) ) {
				return false;
			}
		}

		return true;
	}
}

class FunctionScoreChain {
	/**
	 * List of allowed function_score param
	 * we keep boost and boost_mode even if they do not make sense
	 * here since we do not allow to specify the query param.
	 * The query will be MatchAll with a score to 1.
	 *
	 * @var string[]
	 */
	private static $functionScoreParams = [
		'boost',
		'boost_mode',
		'max_boost',
		'score_mode',
		'min_score'
	];

	/**
	 * @var SearchContext
	 */
	private $context;

	/**
	 * @var FunctionScoreDecorator
	 */
	private $functionScore;

	/**
	 * @var array the function score chain
	 */
	private $chain;

	/**
	 * @var string the name of the chain
	 */
	private $chainName;

	/**
	 * Builds a new function score chain.
	 *
	 * @param SearchContext $context
	 * @param string $chainName the name of the chain (must be a valid
	 *  chain in wgCirrusSearchRescoreFunctionScoreChains)
	 * @throws InvalidRescoreProfileException
	 */
	public function __construct( SearchContext $context, $chainName ) {
		$this->chainName = $chainName;
		$this->context = $context;
		$this->functionScore = new FunctionScoreDecorator();
		$this->chain = $context->getConfig()->getElement( 'CirrusSearchRescoreFunctionScoreChains', $chainName );
		if ( $this->chain === null ) {
			throw new InvalidRescoreProfileException( "Unknown rescore function chain $chainName" );
		}

		$params = array_intersect_key( $this->chain, array_flip( self::$functionScoreParams ) );
		foreach ( $params as $param => $value ) {
			$this->functionScore->setParam( $param, $value );
		}
	}

	/**
	 * @return FunctionScore|null the rescore query or null none of functions were
	 *  needed.
	 * @throws InvalidRescoreProfileException
	 */
	public function buildRescoreQuery() {
		if ( !isset( $this->chain['functions'] ) ) {
			throw new InvalidRescoreProfileException( "No functions defined in chain {$this->chainName}." );
		}
		foreach ( $this->chain['functions'] as $func ) {
			$impl = $this->getImplementation( $func );
			$impl->append( $this->functionScore );
		}
		// Add extensions
		if ( !empty( $this->chain['add_extensions'] ) ) {
			foreach ( $this->context->getExtraScoreBuilders() as $extBuilder ) {
				$extBuilder->append( $this->functionScore );
			}
		}
		if ( !$this->functionScore->isEmptyFunction() ) {
			return $this->functionScore;
		}
		return null;
	}

	/**
	 * @param array $func
	 * @return FunctionScoreBuilder
	 * @throws InvalidRescoreProfileException
	 * @suppress PhanTypeMismatchReturn phan does not understand hooks and by-ref parameters
	 */
	private function getImplementation( $func ) {
		$weight = isset( $func['weight'] ) ? $func['weight'] : 1;
		switch ( $func['type'] ) {
		case 'boostlinks':
			return new IncomingLinksFunctionScoreBuilder( $this->context, $weight );
		case 'recency':
			return new PreferRecentFunctionScoreBuilder( $this->context, $weight );
		case 'templates':
			return new BoostTemplatesFunctionScoreBuilder( $this->context, $weight );
		case 'namespaces':
			return new NamespacesFunctionScoreBuilder( $this->context, $weight );
		case 'language':
			return new LangWeightFunctionScoreBuilder( $this->context, $weight );
		case 'custom_field':
			return new CustomFieldFunctionScoreBuilder( $this->context, $weight, $func['params'] );
		case 'script':
			return new ScriptScoreFunctionScoreBuilder( $this->context, $weight, $func['script'] );
		case 'logscale_boost':
			return new LogScaleBoostFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
		case 'satu':
			return new SatuFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
		case 'log_multi':
			return new LogMultFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
		case 'geomean':
			return new GeoMeanFunctionScoreBuilder( $this->context, $weight,  $func['params'] );
		case 'term_boost':
			return new TermBoostScoreBuilder( $this->context, $weight,  $func['params'] );
		default:
			$builder = null;
			Hooks::run( 'CirrusSearchScoreBuilder', [ $func, $this->context, &$builder ] );
			if ( !$builder ) {
				throw new InvalidRescoreProfileException( "Unknown function score type {$func['type']}." );
			}
			/**
			 * @var $builder FunctionScoreBuilder
			 */
			return $builder;
		}
	}
}

/**
 * This is useful to check if the function score is empty
 * Function score builders may not add any function if some
 * criteria are not met. If there's no function we should not
 * not build the rescore query.
 * @todo: find another pattern to deal with this problem and avoid
 * this strong dependency to FunctionScore::addFunction signature.
 */
class FunctionScoreDecorator extends FunctionScore {
	/** @var int */
	private $size = 0;

	/**
	 * @param string $functionType
	 * @param array|float $functionParams
	 * @param AbstractQuery|null $filter
	 * @param float|null $weight
	 * @return self
	 */
	public function addFunction( $functionType, $functionParams, AbstractQuery $filter = null, $weight = null ) {
		$this->size++;
		return parent::addFunction( $functionType, $functionParams, $filter, $weight );
	}

	/**
	 * @return bool true if this function score is empty
	 */
	public function isEmptyFunction() {
		return $this->size == 0;
	}

	/**
	 * @return int the number of added functions.
	 */
	public function getSize() {
		return $this->size;
	}

	/**
	 * Default elastica behaviour is to use class name
	 * as property name. We must override this function
	 * to force the name to function_score
	 *
	 * @return string
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

	/**
	 * @var float global weight of this function score builder
	 */
	protected $weight;

	/**
	 * @param SearchContext $context the search context
	 * @param float $weight the global weight
	 */
	public function __construct( SearchContext $context, $weight ) {
		$this->context = $context;
		$this->weight = $this->getOverriddenFactor( $weight );
	}

	/**
	 * Append functions to the function score $container
	 *
	 * @param FunctionScore $container
	 */
	abstract public function append( FunctionScore $container );

	/**
	 * Utility method to extract a factor (float) that can
	 * be overridden by a config value or an URI param
	 *
	 * @param float|array $value
	 * @return float
	 */
	protected function getOverriddenFactor( $value ) {
		if ( is_array( $value ) ) {
			$returnValue = (float)$value['value'];

			if ( isset( $value['config_override'] ) ) {
				// Override factor with config
				$fromConfig = $this->context->getConfig()->get( $value['config_override'] );
				if ( $fromConfig !== null ) {
					$returnValue = (float)$fromConfig;
				}
			}

			if ( isset( $value['uri_param_override'] ) ) {
				// Override factor with uri param
				$uriParam = $value['uri_param_override'];
				$request = \RequestContext::getMain()->getRequest();
				if ( $request ) {
					$fromUri = $request->getVal( $uriParam );
					if ( $fromUri !== null && is_numeric( $fromUri ) ) {
						$returnValue = (float)$fromUri;
					}
				}
			}
			return $returnValue;
		} else {
			return (float)$value;
		}
	}
}

/**
 * Builds a set of functions with boosted templates
 * Uses a weight function with a filter for each template.
 * The list of boosted templates is read from SearchContext
 */
class BoostTemplatesFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var float[] Template boost values keyed by template name
	 */
	private $boostTemplates;

	/**
	 * @var float[][] Template boost values with wiki id at top level,
	 *  template at second level, and boost as the value.
	 */
	private $extraIndexBoostTemplates;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 */
	public function __construct( SearchContext $context, $weight ) {
		parent::__construct( $context, $weight );
		// Use the boosted template from query string if available
		$this->boostTemplates = $context->getBoostTemplatesFromQuery();
		// Use the boosted templates from extra indexes if available
		$this->extraIndexBoostTemplates = $context->getExtraIndexBoostTemplates();
		// empty array may be returned here in the case of a syntax error
		// @todo: verify that this is what we want: in case of a syntax error
		// we disable default boost templates.
		if ( $this->boostTemplates === null ) {
			// Fallback to default otherwise
			$this->boostTemplates =
				Util::getDefaultBoostTemplates( $context->getConfig() );
		}
	}

	public function append( FunctionScore $functionScore ) {
		if ( $this->boostTemplates ) {
			foreach ( $this->boostTemplates as $name => $weight ) {
				$match = new \Elastica\Query\Match();
				$match->setFieldQuery( 'template', $name );
				$functionScore->addWeightFunction( $weight * $this->weight, $match );
			}
		}
		foreach ( $this->extraIndexBoostTemplates as $wiki => $boostTemplates ) {
			foreach ( $boostTemplates as $name => $weight ) {
				$bool = new \Elastica\Query\BoolQuery();
				$bool->addMust( ( new \Elastica\Query\Match() )
					->setFieldQuery( 'wiki', $wiki ) );
				$bool->addMust( ( new \Elastica\Query\Match() )
					->setFieldQuery( 'template', $name ) );
				$functionScore->addWeightFunction( $weight * $this->weight, $bool );
			}
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
		$this->namespacesToBoost = $this->context->getNamespaces() ?: MWNamespace::getValidNamespaces();
		if ( !$this->namespacesToBoost || count( $this->namespacesToBoost ) == 1 ) {
			// nothing to boost, no need to initialize anything else.
			return;
		}
		$this->normalizedNamespaceWeights = [];
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
	 *
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
				$weightToNs[$key] = [ $ns ];
			} else {
				$weightToNs[$key][] = $ns;
			}
		}
		foreach ( $weightToNs as $weight => $namespaces ) {
			$filter = new \Elastica\Query\Terms( 'namespace', $namespaces );
			$functionScore->addWeightFunction( $weight, $filter );
		}
	}
}

/**
 * Builds a function that boosts incoming links
 * formula is log( incoming_links + 2 )
 */
class IncomingLinksFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @param SearchContext $context
	 * @param float $weight
	 */
	public function __construct( SearchContext $context, $weight ) {
		parent::__construct( $context, $weight );
	}

	public function append( FunctionScore $functionScore ) {
		$functionScore->addFunction( 'field_value_factor', [
			'field' => 'incoming_links',
			'modifier' => 'log2p',
			'missing' => 0,
		] );
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

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param array $profile
	 */
	public function __construct( SearchContext $context, $weight, $profile ) {
		parent::__construct( $context, $weight );
		if ( isset( $profile['factor'] ) ) {
			$profile['factor'] = $this->getOverriddenFactor( $profile['factor'] );
		}
		$this->profile = $profile;
	}

	public function append( FunctionScore $functionScore ) {
		if ( isset( $this->profile['factor'] ) && $this->profile['factor'] === 0.0 ) {
			// If factor is 0 this function score will have no impact.
			return;
		}
		$functionScore->addFunction( 'field_value_factor', $this->profile, null, $this->weight );
	}
}

/**
 * Normalize values in the [0,1] range
 * Allows to set:
 * - a scale
 * - midpoint
 * It will generate a log scale factor where :
 * - f(0) = 0
 * - f(scale) = 1
 * - f(midpoint) = 0.5
 *
 * Based on log10( a . x + 1 ) / log10( a . M + 1 )
 * a: a factor used to adjust the midpoint
 * M: the max value used to scale
 *
 */
class LogScaleBoostFunctionScoreBuilder extends FunctionScoreBuilder {
	/** @var string */
	private $field;
	/** @var int */
	private $impact;
	/** @var float */
	private $midpoint;
	/** @var float */
	private $scale;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param array $profile
	 * @throws InvalidRescoreProfileException
	 */
	public function __construct( SearchContext $context, $weight, $profile ) {
		parent::__construct( $context, $weight );

		if ( isset( $profile['midpoint'] ) ) {
			$this->midpoint = $this->getOverriddenFactor( $profile['midpoint'] );
		} else {
			throw new InvalidRescoreProfileException( 'midpoint is mandatory' );
		}

		if ( isset( $profile['scale'] ) ) {
			$this->scale = $this->getOverriddenFactor( $profile['scale'] );
		} else {
			throw new InvalidRescoreProfileException( 'scale is mandatory' );
		}

		if ( isset( $profile['field' ] ) ) {
			$this->field = $profile['field'];
		} else {
			throw new InvalidRescoreProfileException( 'field is mandatory' );
		}
	}

	/**
	 * find the factor to adjust the scale center,
	 * it's like finding the log base to have f(N) = 0.5
	 *
	 * @param float $M
	 * @param float $N
	 * @return float
	 * @throws InvalidRescoreProfileException
	 */
	private function findCenterFactor( $M, $N ) {
		// Neutral point is found by resolving
		// log10( x . N + 1 ) / log10( x . M + 1 ) = 0.5
		// it's equivalent to resolving:
		// N²x² + (2N - M)x + 1 = 0
		// so we we use the quadratic formula:
		// (-(2N-M) + sqrt((2N-M)²-4N²)) / 2N²
		if ( 4 * $N >= $M ) {
			throw new InvalidRescoreProfileException( 'The midpoint point cannot be higher than scale/4' );
		}
		return ( -( 2 * $N - $M ) + sqrt( ( 2 * $N - $M ) * ( 2 * $N - $M ) - 4 * $N * $N ) ) / ( 2 * $N * $N );
	}

	public function append( FunctionScore $functionScore ) {
		if ( $this->impact == 0 ) {
			return;
		}
		$formula = $this->getScript();

		$functionScore->addScriptScoreFunction( new \Elastica\Script\Script( $formula, null, 'expression' ), null, $this->weight );
	}

	/**
	 * @return string
	 */
	public function getScript() {
		$midFactor = $this->findCenterFactor( $this->scale, $this->midpoint );
		$formula = "log10($midFactor * min(doc['{$this->field}'].value,{$this->scale}) + 1)";
		$formula .= "/log10($midFactor * {$this->scale} + 1)";
		return $formula;
	}
}

/**
 * Saturation function based on x/(k+x), k is a parameter
 * to control how fast the function saturates.
 * NOTE: that satu is always 0.5 when x == k.
 * Parameter a is added to form a sigmoid : x^a/(k^a+x^a)
 * Based on http://research.microsoft.com/pubs/65239/craswell_sigir05.pdf
 * This function is suited to apply a new factor in a weighted sum.
 */
class SatuFunctionScoreBuilder extends FunctionScoreBuilder {
	/** @var float */
	private $k;
	/** @var float */
	private $a;
	/** @var string */
	private $field;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param array $profile
	 * @throws InvalidRescoreProfileException
	 */
	public function __construct( SearchContext $context, $weight, $profile ) {
		parent::__construct( $context, $weight );
		if ( isset( $profile['k'] ) ) {
			$this->k = $this->getOverriddenFactor( $profile['k'] );
			if ( $this->k <= 0 ) {
				throw new InvalidRescoreProfileException( 'Param k must be > 0' );
			}
		} else {
			throw new InvalidRescoreProfileException( 'Param k is mandatory' );
		}

		if ( isset( $profile['a'] ) ) {
			$this->a = $this->getOverriddenFactor( $profile['a'] );
			if ( $this->a <= 0 ) {
				throw new InvalidRescoreProfileException( 'Param a must be > 0' );
			}
		} else {
			$this->a = 1;
		}

		if ( isset( $profile['field'] ) ) {
			$this->field = $profile['field'];
		} else {
			throw new InvalidRescoreProfileException( 'Param field is mandatory' );
		}
	}

	public function append( FunctionScore $functionScore ) {
		$formula = $this->getScript();
		$functionScore->addScriptScoreFunction( new \Elastica\Script\Script( $formula, null, 'expression' ), null, $this->weight );
	}

	/**
	 * @return string
	 */
	public function getScript() {
		$formula = "pow(doc['{$this->field}'].value , {$this->a}) / ";
		$formula .= "( pow(doc['{$this->field}'].value, {$this->a}) + ";
		$formula .= "pow({$this->k},{$this->a}))";
		return $formula;
	}
}

/**
 * simple log(factor*field+2)^impact
 * Useful to control the impact when applied in a multiplication.
 */
class LogMultFunctionScoreBuilder extends FunctionScoreBuilder {
	/** @var float */
	private $impact;
	/** @var float */
	private $factor;
	/** @var string */
	private $field;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param array $profile
	 * @throws InvalidRescoreProfileException
	 */
	public function __construct( SearchContext $context, $weight, $profile ) {
		parent::__construct( $context, $weight );
		if ( isset( $profile['impact'] ) ) {
			$this->impact = $this->getOverriddenFactor( $profile['impact'] );
			if ( $this->impact <= 0 ) {
				throw new InvalidRescoreProfileException( 'Param impact must be > 0' );
			}
		} else {
			throw new InvalidRescoreProfileException( 'Param impact is mandatory' );
		}

		if ( isset( $profile['factor'] ) ) {
			$this->factor = $this->getOverriddenFactor( $profile['factor'] );
			if ( $this->factor <= 0 ) {
				throw new InvalidRescoreProfileException( 'Param factor must be > 0' );
			}
		} else {
			$this->factor = 1;
		}

		if ( isset( $profile['field'] ) ) {
			$this->field = $profile['field'];
		} else {
			throw new InvalidRescoreProfileException( 'Param field is mandatory' );
		}
	}

	public function append( FunctionScore $functionScore ) {
		$formula = "pow(log10({$this->factor} * doc['{$this->field}'].value + 2), {$this->impact})";
		$functionScore->addScriptScoreFunction( new \Elastica\Script\Script( $formula, null, 'expression' ), null, $this->weight );
	}
}

/**
 * Utility function to compute a weighted geometric mean.
 * According to https://en.wikipedia.org/wiki/Weighted_geometric_mean
 * this is equivalent to exp ( w1*ln(value1)+w2*ln(value2) / (w1 + w2) ) ^ impact
 * impact is applied as a power factor because this function is applied in a
 * multiplication.
 * Members can use only LogScaleBoostFunctionScoreBuilder or SatuFunctionScoreBuilder
 * these are the only functions that normalize the value in the [0,1] range.
 */
class GeoMeanFunctionScoreBuilder extends FunctionScoreBuilder {
	/** @var float */
	private $impact;
	/** @var array[] */
	private $scriptFunctions = [];
	/** @var float */
	private $epsilon = 0.0000001;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param array $profile
	 * @throws InvalidRescoreProfileException
	 */
	public function __construct( SearchContext $context, $weight, $profile ) {
		parent::__construct( $context, $weight );

		if ( isset( $profile['impact'] ) ) {
			$this->impact = $this->getOverriddenFactor( $profile['impact'] );
			if ( $this->impact <= 0 ) {
				throw new InvalidRescoreProfileException( 'Param impact must be > 0' );
			}
		} else {
			throw new InvalidRescoreProfileException( 'Param impact is mandatory' );
		}

		if ( isset( $profile['epsilon'] ) ) {
			$this->epsilon = $this->getOverriddenFactor( $profile['epsilon'] );
		}

		if ( !isset( $profile['members'] ) || !is_array( $profile['members'] ) ) {
			throw new InvalidRescoreProfileException( 'members must be an array of arrays' );
		}
		foreach ( $profile['members'] as $member ) {
			if ( !is_array( $member ) ) {
				throw new InvalidRescoreProfileException( "members must be an array of arrays" );
			}
			if ( !isset( $member['weight'] ) ) {
				$weight = 1;
			} else {
				$weight = $this->getOverriddenFactor( $member['weight'] );
			}
			$function = [ 'weight' => $weight ];
			switch ( $member['type'] ) {
			case 'satu':
				$function['script'] = new SatuFunctionScoreBuilder( $this->context, 1, $member['params'] );
				break;
			case 'logscale_boost':
				$function['script'] = new LogScaleBoostFunctionScoreBuilder( $this->context, 1, $member['params'] );
				break;
			default:
				throw new InvalidRescoreProfileException( "Unsupported function in {$member['type']}." );
			}
			$this->scriptFunctions[] = $function;
		}
		if ( count( $this->scriptFunctions ) < 2 ) {
			throw new InvalidRescoreProfileException( "At least 2 members are needed to compute a geometric mean." );
		}
	}

	/**
	 * Build a weighted geometric mean using a logarithmic arithmetic mean.
	 * exp(w1*ln(value1)+w2*ln(value2) / (w1+w2))
	 * NOTE: We need to use an epsilon value in case value is 0.
	 *
	 * @return string|null the script
	 */
	public function getScript() {
		$formula = "pow(";
		$formula .= "exp((";
		$first = true;
		$sumWeight = 0;
		foreach ( $this->scriptFunctions as $func ) {
			if ( $first ) {
				$first = false;
			} else {
				$formula .= " + ";
			}
			$sumWeight += $func['weight'];
			$formula .= "{$func['weight']}*ln(max(";

			$formula .= $func['script']->getScript();

			$formula .= ", {$this->epsilon}))";
		}
		if ( $sumWeight == 0 ) {
			return null;
		}
		$formula .= ")";
		$formula .= "/ $sumWeight )";
		$formula .= ", {$this->impact})"; // pow(
		return $formula;
	}

	public function append( FunctionScore $functionScore ) {
		$formula = $this->getScript();
		if ( $formula != null ) {
			$functionScore->addScriptScoreFunction( new \Elastica\Script\Script( $formula, null, 'expression' ), null, $this->weight );
		}
	}
}

/**
 * Builds a script score boost documents on the timestamp field.
 * Reads its param from SearchContext: preferRecentDecayPortion and preferRecentHalfLife
 * Can be initialized by config for full text and by special syntax in user query
 */
class PreferRecentFunctionScoreBuilder extends FunctionScoreBuilder {
	public function append( FunctionScore $functionScore ) {
		if ( !$this->context->hasPreferRecentOptions() ) {
			return;
		}
		// Convert half life for time in days to decay constant for time in milliseconds.
		$decayConstant = log( 2 ) / $this->context->getPreferRecentHalfLife() / 86400000;
		$parameters = [
			'decayConstant' => $decayConstant,
			'decayPortion' => $this->context->getPreferRecentDecayPortion(),
			'nonDecayPortion' => 1 - $this->context->getPreferRecentDecayPortion(),
			'now' => time() * 1000
		];

		// e^ct where t is last modified time - now which is negative
		$exponentialDecayExpression = "exp(decayConstant * (doc['timestamp'].value - now))";
		if ( $this->context->getPreferRecentDecayPortion() !== 1.0 ) {
			$exponentialDecayExpression = "$exponentialDecayExpression * decayPortion + nonDecayPortion";
		}
		$functionScore->addScriptScoreFunction( new \Elastica\Script\Script( $exponentialDecayExpression,
			$parameters, 'expression' ), null, $this->weight );
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

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 */
	public function __construct( SearchContext $context, $weight ) {
		parent::__construct( $context, $weight );
		$this->userLang = $this->context->getConfig()->getUserLanguage();
		$this->userWeight = $this->context->getConfig()->getElement( 'CirrusSearchLanguageWeight', 'user' );
		$this->wikiLang = $this->context->getConfig()->get( 'LanguageCode' );
		$this->wikiWeight = $this->context->getConfig()->getElement( 'CirrusSearchLanguageWeight', 'wiki' );
	}

	public function append( FunctionScore $functionScore ) {
		// Boost pages in a user's language
		if ( $this->userWeight ) {
			$functionScore->addWeightFunction(
				$this->userWeight * $this->weight,
				new \Elastica\Query\Term( [ 'language' => $this->userLang ] )
			);
		}

		// And a wiki's language, if it's different
		if ( $this->wikiWeight && $this->userLang != $this->wikiLang ) {
			$functionScore->addWeightFunction(
				$this->wikiWeight * $this->weight,
				new \Elastica\Query\Term( [ 'language' => $this->wikiLang ] )
			);
		}
	}
}

/**
 * A function score that builds a script_score.
 * see: https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-function-score-query.html#function-script-score
 * NOTE: only lucene expression script engine is supported.
 */
class ScriptScoreFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var string the script
	 */
	private $script;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param string $script
	 */
	public function __construct( SearchContext $context, $weight, $script ) {
		parent::__construct( $context, $weight );
		$this->script = $script;
	}

	public function append( FunctionScore $functionScore ) {
		$functionScore->addScriptScoreFunction(
			new \Elastica\Script\Script( $this->script, null, 'expression' ),
			null, $this->weight );
	}
}

/**
 * Boost score when certain field is matched with certain term.
 * Config:
 * [ 'field_name' => ['match1' => WEIGHT1, ...], ...]
 * @package CirrusSearch\Search
 */
class TermBoostScoreBuilder extends FunctionScoreBuilder {
	/** @var array[] */
	private $fields;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param array $profile
	 */
	public function __construct( SearchContext $context, $weight, $profile ) {
		parent::__construct( $context, $weight );
		$this->fields = $profile;
	}

	public function append( FunctionScore $functionScore ) {
		foreach ( $this->fields as $field => $matches ) {
			foreach ( $matches as $match => $matchWeight ) {
				$functionScore->addWeightFunction(
					$matchWeight * $this->weight,
					new \Elastica\Query\Term( [ $field => $match ] )
				);
			}
		}
	}
}

/**
 * Exception thrown if an error has been detected in the rescore profiles
 */
class InvalidRescoreProfileException extends \Exception {
}
