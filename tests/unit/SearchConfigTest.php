<?php

namespace CirrusSearch;

class SearchConfigTest extends \MediaWikiTestCase {
	public function testInterWikiConfig() {
		$config = new SearchConfig();
		$config = new \HashConfig( $config->getConfigVars(wfWikiID(), SearchConfig::CIRRUS_VAR_PREFIX) );
		$prefix = SearchConfig::CIRRUS_VAR_PREFIX;
		foreach( $GLOBALS as $n => $v ) {
			if ( $v === null ) {
				continue;
			}
			if ( strncmp( $n, $prefix, strlen( $prefix ) ) == 0
				|| in_array( $n, SearchConfig::getNonCirrusConfigVarNames() )
			) {
				$this->assertEquals( $v, $config->get( $n ), "Var $n" );
			}
		}
	}
}
