<?php

namespace CirrusSearch\Parser;

use Wikimedia\NormalizedException\INormalizedException;
use Wikimedia\NormalizedException\NormalizedExceptionTrait;

/**
 * Problem related to ParsedQueryClassifier
 * @see FTQueryClassifiersRepository
 * @see ParsedQueryClassifier
 */
class ParsedQueryClassifierException extends \RuntimeException implements INormalizedException {
	use NormalizedExceptionTrait;

	public function __construct( string $message, array $context = [] ) {
		$this->normalizedMessage = $message;
		$this->messageContext = $context;
		parent::__construct( self::getMessageFromNormalizedMessage( $message, $context ) );
	}
}
