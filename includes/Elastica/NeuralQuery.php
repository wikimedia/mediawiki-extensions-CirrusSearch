<?php
/**
 * Implementation of neural query for OpenSearch
 *
 * @link https://opensearch.org/docs/latest/query-dsl/specialized/neural/
 *
 * @license GPL-2.0-or-later
 */

namespace CirrusSearch\Elastica;

use Elastica\Query\AbstractQuery;

class NeuralQuery extends AbstractQuery {
	private string $field;

	/**
	 * @param string $field The vector field to search
	 * @param string $queryText The query text to be embedded by the model
	 * @param int $k The number of nearest neighbors to return
	 */
	public function __construct( string $field, string $queryText, int $k ) {
		$this->field = $field;
		$this->setQueryText( $queryText )
			->setK( $k );
	}

	/**
	 * @return array
	 */
	public function toArray(): array {
		return [
			'neural' => [
				$this->field => $this->getParams(),
			],
		];
	}

	/**
	 * Set the query text to be embedded.
	 *
	 * @param string $queryText
	 * @return self
	 */
	public function setQueryText( string $queryText ): self {
		$this->setParam( 'query_text', $queryText );
		return $this;
	}

	/**
	 * Set the model ID to use for embedding.
	 *
	 * @param string $modelId
	 * @return self
	 */
	public function setModelId( string $modelId ): self {
		$this->setParam( 'model_id', $modelId );
		return $this;
	}

	/**
	 * Set the number of nearest neighbors to return.
	 *
	 * @param int $k
	 * @return self
	 */
	public function setK( int $k ): self {
		$this->setParam( 'k', $k );
		return $this;
	}
}
