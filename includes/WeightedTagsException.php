<?php

namespace CirrusSearch;

use RuntimeException;
use Throwable;
use Wikimedia\NormalizedException\INormalizedException;
use Wikimedia\NormalizedException\NormalizedExceptionTrait;

class WeightedTagsException extends RuntimeException implements INormalizedException {
	use NormalizedExceptionTrait;

	/**
	 * @param string $message message template
	 * @param array|null $context optional contextual information (used for placeholders in template)
	 * @param Throwable|null $previous optional cause
	 */
	public function __construct( string $message, ?array $context = [], ?Throwable $previous = null ) {
		$this->normalizedMessage = $message;
		$this->messageContext = $context;

		parent::__construct(
			$this->getMessageFromNormalizedMessage( $this->normalizedMessage, $this->messageContext ), 0, $previous
		);
	}
}
