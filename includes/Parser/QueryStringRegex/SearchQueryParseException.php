<?php

namespace CirrusSearch\Parser\QueryStringRegex;

use MediaWiki\Status\Status;
use Wikimedia\Message\MessageParam;
use Wikimedia\Message\MessageSpecifier;

class SearchQueryParseException extends \Exception {
	/**
	 * @var string
	 */
	private $messageId;

	/**
	 * @var array
	 */
	private $params;

	/**
	 * @param string $messageId
	 * @param MessageParam|MessageSpecifier|string|int|float|list<MessageParam|MessageSpecifier|string|int|float> ...$params
	 */
	public function __construct( string $messageId, ...$params ) {
		parent::__construct( $messageId );
		$this->messageId = $messageId;
		$this->params = $params;
	}

	/**
	 * Transform this exception as a Status object containing the message to display to the user
	 * @return Status
	 */
	public function asStatus(): Status {
		return Status::newFatal( $this->messageId, ...$this->params );
	}
}
