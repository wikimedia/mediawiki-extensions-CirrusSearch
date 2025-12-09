<?php

namespace CirrusSearch;

use MediaWiki\Actions\FormlessAction;
use MediaWiki\MediaWikiServices;

/**
 * action=cirrusDump handler.  Dumps contents of Elasticsearch indexes for the
 * page.
 *
 * @license GPL-2.0-or-later
 */
class Dump extends FormlessAction {
	/** @inheritDoc */
	public function onView() {
		// Disable regular results
		$this->getOutput()->disable();

		$response = $this->getRequest()->response();
		$response->header( 'Content-type: application/json; charset=UTF-8' );

		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
		$conn = new Connection( $config );
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
		$searcher = new Searcher( $conn, 0, 0, $config, [], $this->getUser() );

		/** @phan-suppress-next-line PhanUndeclaredMethod Phan doesn't know $config is a SearchConfig */
		$docId = $config->makeId( $this->getTitle()->getArticleID() );
		$esSources = $searcher->get( [ $docId ], true );
		if ( !$esSources->isOK() ) {
			// echo for consistency with below
			echo '{"error": "exception has been logged"}';
			return null;
		}
		$esSources = $esSources->getValue();

		$result = [];
		foreach ( $esSources as $esSource ) {
			$result[] = [
				'_index' => $esSource->getIndex(),
				'_type' => $esSource->getType(),
				'_id' => $esSource->getId(),
				'_version' => $esSource->getVersion(),
				'_source' => $esSource->getData(),
			];
		}
		// Echoing raw json to avoid any mangling that would prevent providing
		// the resulting structures to elasticsearch.
		echo json_encode( $result );
		return null;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'cirrusdump';
	}

	/**
	 * @return bool
	 */
	public function requiresWrite() {
		return false;
	}

	/**
	 * @return bool
	 */
	public function requiresUnblock() {
		return false;
	}
}
