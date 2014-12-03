<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;

abstract class Validator {
	/**
	 * @var Maintenance
	 */
	protected $out;

	/**
	 * @param Maintenance $out Maintenance object, to relay output to.
	 */
	public function __construct( Maintenance $out = null ) {
		$this->out = $out;
	}

	/**
	 * @return bool True if validation succeeds, false if it fails.
	 */
	abstract public function validate();

	/**
	 * @param string $message
	 * @param mixed $channel
	 */
	protected function output( $message, $channel = null ) {
		if ( $this->out ) {
			$this->out->output( $message, $channel );
		}
	}

	/**
	 * @param string $message
	 */
	protected function outputIndented( $message ) {
		if ( $this->out ) {
			$this->out->outputIndented( $message );
		}
	}

	/**
	 * @param string $err
	 * @param int $die
	 */
	protected function error( $err, $die = 0 ) {
		if ( $this->out ) {
			$this->out->error( $err, $die );
		}

		$die = intval( $die );
		if ( $die > 0 ) {
			die( $die );
		}
	}
}
