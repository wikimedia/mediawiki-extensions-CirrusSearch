<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// To migrate later
$cfg['suppress_issue_types'][] = 'MediaWikiNoBaseException';
$cfg['suppress_issue_types'][] = 'MediaWikiNoEmptyIfDefined';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'profiles/',
		'../../extensions/Elastica',
		'../../extensions/BetaFeatures',
		'../../extensions/SiteMatrix',
		'../../extensions/EventBus',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/Elastica',
		'../../extensions/BetaFeatures',
		'../../extensions/SiteMatrix',
		'../../extensions/EventBus',
	]
);

return $cfg;
