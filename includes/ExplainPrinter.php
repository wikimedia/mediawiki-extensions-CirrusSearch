<?php

namespace CirrusSearch;

use LuceneExplain\ExplainFactory;

/**
 * Formats the result of elasticsearch explain to a (slightly) more
 * readable html format than raw json.
 *
 * @license GPL-2.0-or-later
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
	 * @param mixed $queryResult Elasticsearch result
	 * @return string
	 */
	public function format( mixed $queryResult ) {
		$result = [];
		if ( isset( $queryResult['result']['hits']['hits'] ) ) {
			$queryResult = [ $queryResult ];
		}
		foreach ( $queryResult as $qr ) {
			$result[] = "<div><h2>{$qr['description']} on {$qr['path']}</h2></div>";
			foreach ( $qr['result']['hits']['hits'] as $hit ) {
				$explain = $this->processExplain( $hit['_explanation'] );
				$result[] =
					"<div>" .
						"<h3>" . htmlentities( $hit['_source']['title'] ) . "</h3>" .
						( isset( $hit['highlight']['text'][0] ) ? "<div>" . $hit['highlight']['text'][0] . "</div>" : "" ) .
						"<table>" .
							"<tr>" .
								"<td>article id</td>" .
								"<td>" . htmlentities( $hit['_id'] ) . "</td>" .
							"</tr><tr>" .
								"<td>ES score</td>" .
								"<td>" . htmlentities( $hit['_score'] ) . "</td>" .
							"</tr><tr>" .
								"<td>ES explain</td>" .
								"<td><pre>" . htmlentities( $explain ) . "</pre></td>" .
							"</tr>" .
						"</table>" .
					"</div>";
			}
		}

		return "<div>" . implode( '', $result ) . "</div>";
	}

	private function formatText( array $explanation, string $indent = "" ): string {
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
