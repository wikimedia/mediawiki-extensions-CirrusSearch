<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\ConfigUtils;
use CirrusSearch\Maintenance\Printer;
use Elastica\Client;
use Elastica\Index;
use MediaWiki\Status\Status;

class IndexHasChangedValidator extends Validator {

	private Index $oldIndex;
	private Index $newIndex;
	private ConfigUtils $configUtils;

	public function __construct( Client $client, Index $oldIndex, Index $newIndex, Printer $out ) {
		parent::__construct( $out );

		$this->oldIndex = $oldIndex;
		$this->newIndex = $newIndex;
		$this->configUtils = new ConfigUtils( $client, $out );
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->out->outputIndented( "Validating new index is different..." );
		if ( !$this->oldIndex->exists() ) {
			$this->out->output( "ok\n" );
			return Status::newGood( true );
		}

		$alias = $this->oldIndex->getName();
		$replacement = $this->newIndex->getName();

		$status = $this->configUtils->isIndex( $alias );
		if ( !$status->isGood() ) {
			$this->out->output( "error\n" );
			return $status;
		}
		if ( $status->getValue() ) {
			$this->out->output( "error\n" );
			return Status::newFatal( "Primary index was expected to be an alias: $alias" );
		}
		$status = $this->configUtils->isIndex( $replacement );
		if ( !$status->isGood() ) {
			$this->out->output( "error\n" );
			return $status;
		}
		if ( !$status->getValue() ) {
			$this->out->output( "error\n" );
			return Status::newFatal(
				"Replacement index was expected to be a real index: {$replacement}"
			);
		}

		$status = $this->configUtils->getIndicesWithAlias( $alias );
		if ( !$status->isGood() ) {
			$this->out->output( "error\n" );
			return $status;
		}
		$liveIndices = $status->getValue();
		if ( in_array( $replacement, $liveIndices ) ) {
			// If old and new are the same index we are doing something like --justMapping
			// and this validator is irrelevant.
			$this->out->output( "same index\n" );
			return Status::newGood( true );
		}

		$equivalent = $this->compareSettings() && $this->compareMapping();
		if ( $equivalent ) {
			$this->output( "no change\n" );
		} else {
			$this->output( "ok\n" );
		}
		return Status::newGood( !$equivalent );
	}

	private function compareSettings() {
		$old = $this->oldIndex->getSettings()->get();
		unset( $old['provided_name'], $old['creation_date'], $old['uuid'] );
		$new = $this->newIndex->getSettings()->get();
		unset( $new['provided_name'], $new['creation_date'], $new['uuid'] );
		return $old == $new;
	}

	private function compareMapping() {
		$old = $this->oldIndex->getMapping();
		$new = $this->newIndex->getMapping();
		return $old == $new;
	}
}
