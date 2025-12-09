<?php
/**
 * @license GPL-2.0-or-later
 */

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\SearchConfig;
use MediaWiki\Context\RequestContext;

abstract class FunctionScoreBuilder implements BoostFunctionBuilder {
	/**
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * @var float global weight of this function score builder
	 */
	protected $weight;

	/**
	 * @param SearchConfig $config the search config
	 * @param float|array $weight the global weight
	 */
	public function __construct( $config, $weight ) {
		$this->config = $config;
		$this->weight = $this->getOverriddenFactor( $weight );
	}

	/**
	 * Utility method to extract a factor (float) that can
	 * be overridden by a config value or an URI param
	 *
	 * @param float|array{value:float,config_override:string,uri_param_override:string} $value
	 * @return float
	 */
	protected function getOverriddenFactor( $value ) {
		if ( is_array( $value ) ) {
			$returnValue = (float)$value['value'];

			if ( isset( $value['config_override'] ) ) {
				// Override factor with config
				$fromConfig = $this->config->get( $value['config_override'] );
				if ( $fromConfig !== null ) {
					$returnValue = (float)$fromConfig;
				}
			}

			if ( isset( $value['uri_param_override'] ) ) {
				// Override factor with uri param
				$uriParam = $value['uri_param_override'];
				$fromUri = RequestContext::getMain()->getRequest()->getVal( $uriParam );
				if ( is_numeric( $fromUri ) ) {
					$returnValue = (float)$fromUri;
				}
			}
			return $returnValue;
		} else {
			return (float)$value;
		}
	}
}
