<?php

namespace CirrusSearch\Hooks;

use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use Wikimedia\Rdbms\IConnectionProvider;

class CirrusSearchApiQuerySiteInfoGeneralInfoHook implements APIQuerySiteInfoGeneralInfoHook {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	public function onAPIQuerySiteInfoGeneralInfo( $module, &$result ) {
		$dbr = $this->dbProvider->getReplicaDatabase( false, 'api' );
		$result['max-page-id'] = (int)$dbr->newSelectQueryBuilder()
			->select( 'MAX(page_id)' )
			->from( 'page' )
			->caller( __METHOD__ )
			->fetchField();
	}
}
