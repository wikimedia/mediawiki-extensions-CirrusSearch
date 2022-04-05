<?php

namespace CirrusSearch\BuildDocument;

/**
 * Exception thrown while building a document for indexing.
 * This exception is considered "recoverable" and the process emitting it might be
 * retried at a later time.
 */
class BuildDocumentException extends \Exception {
	public function __construct( string $message, \Throwable $cause = null ) {
		parent::__construct( $message, 0, $cause );
	}
}
