<?php

namespace CirrusSearch;

class ElasticsearchIntermediaryTest extends \PHPUnit_Framework_TestCase {

	public static function provideExceptions() {
		return array(
			'Regex is rejected' => array(
				'rejected',
				self::newResponseException( 'invalid_regex_exception', 'Syntax error somewhere' ),
			),
			'Too many clauses is rejected' => array(
				'rejected',
				self::newResponseException( 'too_many_clauses', 'Too many boolean clauses' ),
			),
			'NPE is failed' => array(
				'failed',
				self::newResponseException( 'null_pointer_exception', 'Bug somewhere' ),
			),
			'Exotic NPE is unknown' => array(
				'unknown',
				self::newResponseException( 'null_pointer_error', 'Bug in the bug' ),
			),
			'Elastica connection problem is failed' => array(
				'failed',
				new \Elastica\Exception\Connection\HttpException( CURLE_COULDNT_CONNECT ),
			),
			'Elastica connection timeout is failed' => array(
				'failed',
				new \Elastica\Exception\Connection\HttpException( 28 ),
			),
			'null is unkown' => array(
				'unknown',
				null,
			),
		);
	}

	/**
	 * @dataProvider provideExceptions
	 */
	public function testExceptionClassifier( $expected_type, $exception ) {
		$this->assertEquals( $expected_type, ElasticsearchIntermediary::classifyError( $exception ) );
	}

	public static function newResponseException( $type, $message ) {
		return new \Elastica\Exception\ResponseException(
			new \Elastica\Request( 'dummy' ),
			new \Elastica\Response(
				array(
					'error' => array (
						'root_cause' => array( array (
							'type' => $type,
							'reason' => $message,
						) ),
					)
				)
			)
		);
	}
}
