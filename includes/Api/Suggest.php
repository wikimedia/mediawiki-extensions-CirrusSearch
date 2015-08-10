<?php
namespace CirrusSearch\Api;

use ApiBase;
use CirrusSearch\Searcher;
use RequestContext;

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
		$context = RequestContext::getMain();
		$user = $context->getUser();
		$searcher = new Searcher( 0, $this->getParameter( 'limit' ), null, $user );

		$queryText = $this->getParameter( 'text' );
		if ( !$queryText ) {
			return;
		}

		$contextString = $this->getParameter( 'context' );
		if( $contextString ) {
			$context = @json_decode( $contextString, true );
			/*
			 * Validate the context, must be in the form of:
			 * {
			 *   name: { foo: bar, baz: qux }
			 *   name2: { foo: bar, baz: qux }
			 * }
			 *
			 */
			if( !is_array( $context )) {
				$context = null;
			} else {
				foreach( $context as $name => $ctx ) {
					if ( !is_array( $ctx ) ) {
						$this->dieUsage( "Bad context element $name", 'cirrus-badcontext' );
					}
				}
			}
		} else {
			$context = null;
		}

		// TODO: add passing context here,
		// see https://www.elastic.co/guide/en/elasticsearch/reference/current/suggester-context.html
		$result = $searcher->suggest( $queryText, $context );
		if($result->isOK()) {
			$this->getResult()->addValue( null, 'suggest', $result->getValue() );
		} else {
			$this->getResult()->addValue( null, "error", $result->getErrorsArray());
		}
	}

	public function getAllowedParams() {
		return array(
			'text' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			),
			'context' => array(
				ApiBase::PARAM_TYPE => 'string',
			),
			'limit' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_DFLT => 5,
			),
		);
	}
}
