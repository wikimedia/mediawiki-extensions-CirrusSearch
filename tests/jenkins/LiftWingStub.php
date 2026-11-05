<?php

namespace CirrusSearch\Jenkins;

require_once __DIR__ . '/FakeInference.php';

/**
 * Stands in for LiftWing in the semantic integration tests.
 *
 * This is a router script for the php built-in server. The integration test runner starts
 * it in its own container, next to opensearch:
 *
 *   php -S 0.0.0.0:8080 tests/jenkins/LiftWingStub.php
 *
 * The ml-commons connectors from cirrus-toolbox send their requests here. It answers only
 * the request shapes those connectors send. It gives a 500 for anything else, so a change
 * in the request fails the scenario loudly. The connectors send the prod Host header, it's
 * ignored.
 *
 * @license GPL-2.0-or-later
 */
class LiftWingStub {
	private const EMBEDDING_PATH = '/v1/models/jina-embeddings:predict';

	public static function main(): void {
		$method = $_SERVER['REQUEST_METHOD'];
		$uri = $_SERVER['REQUEST_URI'];
		[ $status, $response ] = self::handle( $method, $uri, (string)file_get_contents( 'php://input' ) );
		// The container log is the only record of what opensearch asked for.
		error_log( $status === 200 ? "$status $method $uri" : $response['error'] );
		http_response_code( $status );
		header( 'Content-Type: application/json' );
		echo json_encode( $response );
	}

	/**
	 * @param string $method
	 * @param string $uri
	 * @param string $body
	 * @return array{0:int,1:array} The http status and the response body
	 */
	private static function handle( string $method, string $uri, string $body ): array {
		$request = json_decode( $body, true );
		if ( $method === 'POST' && $uri === self::EMBEDDING_PATH && self::isEmbeddingRequest( $request ) ) {
			return [ 200, self::embeddings( $request['input'] ) ];
		}
		return [ 500, [
			// mb_strcut keeps a whole character, json_encode fails on a split one.
			'error' => "LiftWing stub: unknown request $method $uri " . mb_strcut( $body, 0, 1000 ),
		] ];
	}

	/**
	 * The body the jina embedding connector sends: `{"input": [text, ...]}`.
	 *
	 * @param mixed $request
	 * @return bool
	 */
	private static function isEmbeddingRequest( $request ): bool {
		if ( !is_array( $request ) || array_keys( $request ) !== [ 'input' ] ) {
			return false;
		}
		$input = $request['input'];
		return is_array( $input ) && $input !== [] && array_is_list( $input )
			&& count( array_filter( $input, 'is_string' ) ) === count( $input );
	}

	/**
	 * The OpenAI embedding shape, which the connector's post process function reads.
	 *
	 * @param string[] $inputs
	 * @return array
	 */
	private static function embeddings( array $inputs ): array {
		$data = [];
		foreach ( $inputs as $i => $text ) {
			$data[] = [
				'object' => 'embedding',
				'index' => $i,
				'embedding' => FakeInference::embed( $text ),
			];
		}
		return [ 'object' => 'list', 'data' => $data, 'model' => 'fake' ];
	}
}

if ( PHP_SAPI === 'cli-server' ) {
	LiftWingStub::main();
}
