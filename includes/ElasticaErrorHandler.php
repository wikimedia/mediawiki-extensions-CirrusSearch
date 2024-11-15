<?php

namespace CirrusSearch;

use Elastica\Exception\Bulk\ResponseException as BulkResponseException;
use Elastica\Exception\Connection\HttpException;
use Elastica\Exception\PartialShardFailureException;
use Elastica\Exception\ResponseException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Status\Status;

/**
 * Generic functions for extracting and reporting on errors/exceptions
 * from Elastica.
 */
class ElasticaErrorHandler {

	public static function logRequestResponse( Connection $conn, $message, array $context = [] ) {
		$client = $conn->getClient();
		LoggerFactory::getInstance( 'CirrusSearch' )->info( $message, $context + [
			'cluster' => $conn->getClusterName(),
			'elasticsearch_request' => (string)$client->getLastRequest(),
			'elasticsearch_response' => $client->getLastResponse() !== null ? json_encode( $client->getLastResponse()->getData() ) : "NULL",
		] );
	}

	/**
	 * @param \Elastica\Exception\ExceptionInterface $exception
	 * @return string
	 */
	public static function extractMessage( \Elastica\Exception\ExceptionInterface $exception ) {
		$error = self::extractFullError( $exception );
		return self::formatMessage( $error );
	}

	/**
	 * Extract an error message from an exception thrown by Elastica.
	 * @param \Elastica\Exception\ExceptionInterface $exception exception from which to extract a message
	 * @return array structuerd error from the exception
	 */
	public static function extractFullError( \Elastica\Exception\ExceptionInterface $exception ): array {
		if ( $exception instanceof BulkResponseException ) {
			$actionReasons = [];
			foreach ( $exception->getActionExceptions() as $actionException ) {
				$actionReasons[] = $actionException->getMessage() . ': '
					. self::formatMessage( $actionException->getResponse()->getFullError() );
			}
			return [
				'type' => 'bulk',
				'reason' => $exception->getMessage(),
				'actionReasons' => $actionReasons,
			];
		} elseif ( $exception instanceof HttpException ) {
			return [
				'type' => 'http_exception',
				'reason' => $exception->getMessage()
			];
		} elseif ( !( $exception instanceof ResponseException ) ) {
			// simulate the basic full error structure
			return [
				'type' => 'unknown',
				'reason' => $exception->getMessage()
			];
		}
		if ( $exception instanceof PartialShardFailureException ) {
			// @todo still needs to be fixed, need a way to trigger this
			// failure
			$shardStats = $exception->getResponse()->getShardsStatistics();
			$message = [];
			$type = null;
			foreach ( $shardStats[ 'failures' ] as $failure ) {
				$message[] = $failure['reason']['reason'];
				if ( $type === null ) {
					$type = $failure['reason']['type'];
				}
			}

			return [
				'type' => $type,
				'reason' => 'Partial failure:  ' . implode( ',', $message ),
				'partial' => true
			];
		}

		$response = $exception->getResponse();
		$error = $response->getFullError();
		if ( is_string( $error ) ) {
			$error = [
				'type' => 'unknown',
				'reason' => $error,
			];
		} elseif ( $error === null ) {
			// response wasnt json or didn't contain 'error' key
			// in this case elastica reports nothing.
			$data = $response->getData();
			$parts = [];
			if ( $response->getStatus() !== null ) {
				$parts[] = 'Status code ' . $response->getStatus();
			}
			if ( isset( $data['message'] ) ) {
				// Client puts non-json responses here
				$parts[] = substr( $data['message'], 0, 200 );
			} elseif ( is_string( $data ) && $data !== "" ) {
				// pre-6.0.3 versions of Elastica
				$parts[] = substr( $data, 0, 200 );
			}
			$reason = implode( "; ", $parts );

			$error = [
				'type' => 'unknown',
				'reason' => $reason,
			];
		}

		return $error;
	}

	/**
	 * Broadly classify the error message into failures where
	 * we decided to not serve the query, and failures where
	 * we just failed to answer
	 *
	 * @param \Elastica\Exception\ExceptionInterface|null $exception
	 * @return string Either 'rejected', 'failed' or 'unknown'
	 */
	public static function classifyError( ?\Elastica\Exception\ExceptionInterface $exception = null ) {
		if ( $exception === null ) {
			return 'unknown';
		}
		$error = self::extractFullError( $exception );
		if ( isset( $error['root_cause'][0]['type'] ) ) {
			$error = reset( $error['root_cause'] );
		} elseif ( !( isset( $error['type'] ) && isset( $error['reason'] ) ) ) {
			return 'unknown';
		}

		$heuristics = [
			'rejected' => [
				'type_regexes' => [
					'(^|_)regex_',
					'^too_complex_to_determinize_exception$',
					'^elasticsearch_parse_exception$',
					'^search_parse_exception$',
					'^query_shard_exception$',
					'^illegal_argument_exception$',
					'^too_many_clauses$',
					'^parsing_exception$',
					'^parse_exception$',
					'^script_exception$',
				],
				'msg_regexes' => [
				],
			],
			'failed' => [
				'type_regexes' => [
					'^es_rejected_execution_exception$',
					'^search_phase_execution_exception',
					'^remote_transport_exception$',
					'^search_context_missing_exception$',
					'^null_pointer_exception$',
					'^elasticsearch_timeout_exception$',
					'^retry_on_primary_exception$',
					// These are exceptions thrown by elastica itself
					// (generally connectivity issues in cURL)
					'^http_exception$',
				],
				'msg_regexes' => [
					// ClientException thrown by Elastica
					'^No enabled connection',
					// These are problems raised by the http intermediary layers (nginx/envoy)
					'^Status code 503',
					'^\Qupstream connect error or disconnect/reset\E',
					'^upstream request timeout',
					// see \CirrusSearch\Query\CompSuggestQueryBuilder::postProcess, not ideal to rely
					// on our own exception message for error classification...
					'^\QInvalid response returned from the backend (probable shard failure during the fetch phase)\E',
				],
			],
			'config_issue' => [
				'type_regexes' => [
					'^index_not_found_exception$',
				],
				'msg_regexes' => [
					// for 'bulk' errors index_not_found_exception is set
					// in message and not type
					'index_not_found_exception',
				],
			],
			'memory_issue' => [
				'type_regexes' => [
					'^circuit_breaking_exception$',
				],
				'msg_regexes' => [],
			],
		];

		foreach ( $heuristics as $type => $heuristic ) {
			$regex = implode( '|', $heuristic['type_regexes'] );
			if ( $regex && preg_match( "#$regex#", $error['type'] ) ) {
				return $type;
			}
			$regex = implode( '|', $heuristic['msg_regexes'] );
			if ( $regex && preg_match( "#$regex#", $error['reason'] ) ) {
				return $type;
			}
		}
		return "unknown";
	}

	/**
	 * Does this status represent an Elasticsearch parse error?
	 * @param Status $status Status to check
	 * @return bool is this a parse error?
	 */
	public static function isParseError( Status $status ) {
		foreach ( $status->getMessages() as $msg ) {
			if ( $msg->getKey() === 'cirrussearch-parse-error' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param \Elastica\Exception\ExceptionInterface|null $exception
	 * @return array Two elements, first is Status object, second is string.
	 */
	public static function extractMessageAndStatus( ?\Elastica\Exception\ExceptionInterface $exception = null ) {
		if ( !$exception ) {
			return [ Status::newFatal( 'cirrussearch-backend-error' ), '' ];
		}

		// Lots of times these are the same as getFullError(), but sometimes
		// they're not. I'm looking at you PartialShardFailureException.
		$error = self::extractFullError( $exception );

		// These can be top level errors, or exceptions that don't extend from
		// ResponseException like PartialShardFailureException or errors
		// contacting the cluster.
		if ( !isset( $error['root_cause'][0]['type'] ) ) {
			return [
				Status::newFatal( 'cirrussearch-backend-error' ),
				self::formatMessage( $error )
			];
		}

		// We can have multiple root causes if the error is not the
		// same on different shards. Errors will be deduplicated based
		// on their type. Currently we display only the first one if
		// it happens.
		$cause = reset( $error['root_cause'] );

		if ( $cause['type'] === 'query_shard_exception' ) {
			// The important part of the parse error message is embedded a few levels down
			// and comes before the next new line so lets slurp it up and log it rather than
			// the huge clump of error.
			$shardFailure = reset( $error['failed_shards'] );
			if ( !empty( $shardFailure['reason'] ) ) {
				if ( !empty( $shardFailure['reason']['caused_by'] ) ) {
					$message = $shardFailure['reason']['caused_by']['reason'];
				} else {
					$message = $shardFailure['reason']['reason'];
				}
			} else {
				$message = "???";
			}
			$end = strpos( $message, "\n", 0 );
			if ( $end === false ) {
				$end = strlen( $message );
			}
			$parseError = substr( $message, 0, $end );

			return [
				Status::newFatal( 'cirrussearch-parse-error' ),
				'Parse error on ' . $parseError
			];
		}

		if ( $cause['type'] === 'too_complex_to_determinize_exception' ) {
			return [ Status::newFatal(
				'cirrussearch-regex-too-complex-error' ),
				$cause['reason']
			];
		}

		if ( $cause['type'] === 'script_exception' ) {
			// do not use $cause which won't contain the caused_by chain
			$formattedMessage = self::formatMessage( $error['caused_by'] );
			$formattedMessage .= "\n\t" . implode( "\n\t", $cause['script_stack'] ) . "\n";
			return [
				Status::newFatal( 'cirrussearch-backend-error' ),
				$formattedMessage
			];
		}

		if ( preg_match( '/(^|_)regex_/', $cause['type'] ) ) {
			$syntaxError = $cause['reason'];
			$errorMessage = 'unknown';
			$position = 'unknown';
			// Note: we support only error coming from the extra plugin
			// In the case Cirrus is installed without the plugin and
			// is using the Groovy script to do regex then a generic backend error
			// will be displayed.

			$matches = [];
			// In some cases elastic will serialize the exception by adding
			// an extra message prefix with the exception type.
			// If the exception is serialized through Transport:
			// invalid_regex_exception: expected ']' at position 2
			// Or if the exception is thrown locally by the node receiving the query:
			// expected ']' at position 2
			if ( preg_match( '/(?:[a-z_]+: )?(.+) at position (\d+)/', $syntaxError, $matches ) ) {
				[ , $errorMessage, $position ] = $matches;
			} elseif ( $syntaxError === 'unexpected end-of-string' ) {
				$errorMessage = 'regex too short to be correct';
			}
			$status = Status::newFatal( 'cirrussearch-regex-syntax-error', $errorMessage, $position );

			return [ $status, 'Regex syntax error:  ' . $syntaxError ];
		}

		return [
			Status::newFatal( 'cirrussearch-backend-error' ),
			self::formatMessage( $cause )
		];
	}

	/**
	 * Takes an error and converts it into a useful message. Mostly this is to deal with
	 * errors where the useful part is hidden inside a caused_by chain.
	 * WARNING: In some circumstances, like bulk update failures, this could be multiple
	 * megabytes.
	 *
	 * @param array $error An error array, such as the one returned by extractFullError().
	 * @return string
	 */
	protected static function formatMessage( array $error ) {
		if ( isset( $error['actionReasons'] ) ) {
			$message = $error['type'] . ': ' . $error['reason'];
			foreach ( $error['actionReasons'] as $actionReason ) {
				$message .= "  - $actionReason\n";
			}
			return $message;
		}

		$causeChain = [];
		$errorCursor = $error;
		while ( isset( $errorCursor['caused_by'] ) ) {
			$errorCursor = $errorCursor['caused_by'];
			if ( $errorCursor['reason'] ) {
				$causeChain[] = $errorCursor['reason'];
			}
		}
		$message = $error['type'] . ': ' . $error['reason'];
		if ( $causeChain ) {
			$message .= ' (' . implode( ' -> ', array_reverse( $causeChain ) ) . ')';
		}
		return $message;
	}

}
