<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'CirrusSearch' );
/* Warning disabled for config migration
	wfWarn( 'Deprecated PHP entry point used for CirrusSearch extension. Please use wfLoadExtension ' .
		'instead, see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	); */
	return true;
}

die( 'This version of the CirrusSearch extension requires MediaWiki 1.25+' );
