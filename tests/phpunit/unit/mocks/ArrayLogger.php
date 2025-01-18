<?php

namespace CirrusSearch\Test;

use Psr\Log\AbstractLogger;

class ArrayLogger extends AbstractLogger {
	/** @var array[] */
	private $logs = [];

	/**
	 * @param int $level
	 * @param string $message
	 * @param array $context
	 */
	public function log( $level, $message, array $context = [] ) {
		$this->logs[] = [
			'level' => $level,
			'message' => $message,
			'context' => $context,
		];
	}

	/**
	 * @return array[]
	 */
	public function getLogs() {
		return $this->logs;
	}
}
