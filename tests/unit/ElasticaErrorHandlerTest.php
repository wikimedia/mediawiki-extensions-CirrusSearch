<?php

namespace CirrusSearch;

/**
 * @group CirrusSearch
 */
class ElasticaErrorHandlerTest extends CirrusTestCase {

	public static function provideExceptions() {
		return [
			'Regex is rejected' => [
				'rejected',
				self::newResponseException( 'invalid_regex_exception', 'Syntax error somewhere' ),
			],
			'Too many clauses is rejected' => [
				'rejected',
				self::newResponseException( 'too_many_clauses', 'Too many boolean clauses' ),
			],
			'NPE is failed' => [
				'failed',
				self::newResponseException( 'null_pointer_exception', 'Bug somewhere' ),
			],
			'Exotic NPE is unknown' => [
				'unknown',
				self::newResponseException( 'null_pointer_error', 'Bug in the bug' ),
			],
			'Elastica connection problem is failed' => [
				'failed',
				new \Elastica\Exception\Connection\HttpException( CURLE_COULDNT_CONNECT ),
			],
			'Elastica connection timeout is failed' => [
				'failed',
				new \Elastica\Exception\Connection\HttpException( 28 ),
			],
			'null is unkown' => [
				'unknown',
				null,
			],
		];
	}

	/**
	 * @dataProvider provideExceptions
	 */
	public function testExceptionClassifier( $expected_type, $exception ) {
		$this->assertEquals( $expected_type, ElasticaErrorHandler::classifyError( $exception ) );
	}

	public static function newResponseException( $type, $message ) {
		return new \Elastica\Exception\ResponseException(
			new \Elastica\Request( 'dummy' ),
			new \Elastica\Response(
				[
					'error' => [
						'root_cause' => [ [
							'type' => $type,
							'reason' => $message,
						] ],
					]
				]
			)
		);
	}
}
