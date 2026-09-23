<?php

namespace CirrusSearch;

use LuceneExplain\ExplainFactory;
use MediaWiki\Html\Html;

/**
 * Formats the result of elasticsearch explain to a (slightly) more
 * readable html format than raw json.
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
class ExplainPrinter {
	/** @var string */
	private $type;
	/** @var ExplainFactory */
	private $explainFactory;

	/**
	 * @param string $type Type of explain to print
	 */
	public function __construct( $type ) {
		$this->type = $type;
		$this->explainFactory = new ExplainFactory;
	}

	/**
	 * @param array[] $queryResult Elasticsearch result
	 * @return string
	 */
	public function format( array $queryResult ) {
		$result = [];
		if ( isset( $queryResult['result']['hits']['hits'] ) ) {
			$queryResult = [ $queryResult ];
		}
		foreach ( $queryResult as $qr ) {
			$result[] = Html::rawElement( 'div', [],
				Html::element( 'h2', [], "{$qr['description']} on {$qr['path']}" ) );
			foreach ( $qr['result']['hits']['hits'] as $hit ) {
				$explain = $this->processExplain( $hit['_explanation'] );
				$result[] = Html::rawElement( 'div', [],
					Html::element( 'h3', [], $hit['_source']['title'] ) .
					// The raw response highlights with private use markers rather than
					// html, so the snippet is page text and escapes like any other.
					( isset( $hit['highlight']['text'][0] )
						? Html::element( 'div', [], $hit['highlight']['text'][0] )
						: '' ) .
					Html::rawElement( 'table', [],
						$this->row( 'article id', $hit['_id'] ) .
						$this->row( 'ES score', (string)$hit['_score'] ) .
						Html::rawElement( 'tr', [],
							Html::element( 'td', [], 'ES explain' ) .
							Html::rawElement( 'td', [], Html::element( 'pre', [], $explain ) )
						)
					)
				);
			}
		}

		return Html::rawElement( 'div', [], implode( '', $result ) );
	}

	/**
	 * A label/value table row.
	 *
	 * @param string $label
	 * @param string $value
	 * @return string
	 */
	private function row( string $label, string $value ): string {
		return Html::rawElement( 'tr', [],
			Html::element( 'td', [], $label ) .
			Html::element( 'td', [], $value )
		);
	}

	private function formatText( array $explanation, $indent = "" ) {
		$line = $indent . $explanation['value'] . ' | ' . $explanation['description'] . "\n";
		if ( isset( $explanation['details'] ) ) {
			foreach ( $explanation['details'] as $subExplanation ) {
				$line .= $this->formatText( $subExplanation, "$indent	" );
			}
		}

		return $line;
	}

	/**
	 * Only visible for test purposes
	 *
	 * @param array $explanation
	 * @return string
	 */
	protected function processExplain( array $explanation ) {
		if ( $this->type === 'verbose' ) {
			return $this->formatText( $explanation );
		}
		$explain = $this->explainFactory->createExplain( $explanation );
		if ( $this->type === 'hot' ) {
			return (string)$explain->vectorize();
		} else {
			return (string)$explain;
		}
	}

}
