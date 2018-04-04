<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Wikimedia\Assert\Assert;

/**
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

abstract class FunctionScoreBuilder implements BoostFunctionBuilder {
	/**
	 * @param SearchConfig $config
	 */
	protected $config;

	/**
	 * @var float global weight of this function score builder
	 */
	protected $weight;

	/**
	 * @deprecated you should not depend on the SearchContext
	 * @var SearchContext|null
	 */
	protected $context;

	/**
	 * @param SearchConfig|SearchContext $contextOrConfig the search config
	 * @param float $weight the global weight
	 * NOTE: SearchContext usage is being deprecated
	 */
	public function __construct( $contextOrConfig, $weight ) {
		if ( $contextOrConfig instanceof SearchContext ) {
			/** @suppress PhanDeprecatedProperty */
			$this->context = $contextOrConfig;
			$this->config = $contextOrConfig->getConfig();
		} elseif ( $contextOrConfig instanceof SearchConfig ) {
			$this->config = $contextOrConfig;
		} else {
			Assert::parameter( false, '$contextOrConfig', 'must be a SearchConfig' );
		}
		$this->weight = $this->getOverriddenFactor( $weight );
	}

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
				$fromConfig = $this->config->get( $value['config_override'] );
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

class_alias( FunctionScoreBuilder::class, 'CirrusSearch\Search\FunctionScoreBuilder' );
