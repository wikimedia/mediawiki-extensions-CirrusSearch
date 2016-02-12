<?php
namespace CirrusSearch\Api;

use SearchSuggestion;
use CirrusSearch;

/**
 * Use ElasticSearch suggestion API
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
class Suggest extends ApiBase {

	public function execute() {
		$search = \SearchEngine::create();
		// Force-enable completion suggester
		$search->setFeatureData(CirrusSearch::COMPLETION_SUGGESTER_FEATURE, 'enabled' );
		$search->setNamespaces( array ( NS_MAIN ) );

		$queryText = $this->getParameter( 'text' );

		$limit = $this->getParameter( 'limit' );
		$search->setLimitOffset( $limit );

		$suggestions = $search->completionSearchWithVariants( $queryText );
		// Use the same cache options used by OpenSearch
		$this->getMain()->setCacheMaxAge( $this->getConfig()->get( 'SearchSuggestCacheExpiry' ) );
		$this->getMain()->setCacheMode( 'public' );

		$this->getResult()->addValue( null, 'suggest',
			$suggestions->map( function( SearchSuggestion $sugg ) {
				return array(
					'text' => $sugg->getText(),
					'url' => $sugg->getURL(),
					'score' => $sugg->getScore(),
				);
			} ) );
	}

	/**
	 * This API is experimental and not meant for 3rd party use.
	 * {@inheritDoc}
	 */
	public function isInternal() {
		return true;
	}

	public function getAllowedParams() {
		return array(
			'text' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			),
			'limit' => array(
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_DFLT => 5,
				ApiBase::PARAM_MAX => 20,
				ApiBase::PARAM_MAX2 => 50,
			),
		);
	}
}
