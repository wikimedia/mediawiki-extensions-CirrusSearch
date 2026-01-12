<?php

namespace CirrusSearch;

use MediaWiki\Actions\FormlessAction;
use MediaWiki\MediaWikiServices;

/**
 * action=cirrusSuggestDump handler. Dumps contents of Elasticsearch suggester
 * index for the page.
 *
 * @license GPL-2.0-or-later
 */
class SuggestDump extends FormlessAction {
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

		$esSources = $searcher->getSuggest( [ $docId ] );
		if ( !$esSources->isOK() ) {
			// happens when, for example, the completion index doesn't exist.
			echo '{"error": "exception has been logged"}';
			return null;
		}
		$result = [];
		foreach ( $esSources->getValue() as $esResult ) {
			$result[] = [
				'_index' => $esResult->getIndex(),
				'_type' => $esResult->getType(),
				'_id' => $esResult->getId(),
				'_version' => $esResult->getVersion(),
				'_source' => $esResult->getData(),
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
		return 'cirrussuggestdump';
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
