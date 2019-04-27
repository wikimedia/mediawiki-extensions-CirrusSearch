<?php

namespace CirrusSearch\BuildDocument\Completion;

/**
 * Very simple scoring method based on incoming links
 */
class IncomingLinksScoringMethod implements SuggestScoringMethod {
	/**
	 * @inheritDoc
	 */
	public function score( array $doc ) {
		return $doc['incoming_links'] ?? 0;
	}

	/**
	 * @inheritDoc
	 */
	public function getRequiredFields() {
		return [ 'incoming_links' ];
	}

	/**
	 * @param int $maxDocs
	 */
	public function setMaxDocs( $maxDocs ) {
	}
}
