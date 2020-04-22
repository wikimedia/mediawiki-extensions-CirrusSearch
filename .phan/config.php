<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'profiles/',
		'../../extensions/Elastica',
		'../../extensions/BetaFeatures',
		'../../extensions/SiteMatrix',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/Elastica',
		'../../extensions/BetaFeatures',
		'../../extensions/SiteMatrix',
	]
);

// Temporary for migration. T250806
$cfg['exclude_file_list'] = array_merge(
	$cfg['exclude_file_list'],
	[
		'maintenance/checkIndexes.php',
		'maintenance/cirrusNeedsToBeBuilt.php',
		'maintenance/copySearchIndex.php',
		'maintenance/dumpIndex.php',
		'maintenance/forceSearchIndex.php',
		'maintenance/freezeWritesToCluster.php',
		'maintenance/indexNamespaces.php',
		'maintenance/metastore.php',
		'maintenance/runSearch.php',
		'maintenance/saneitize.php',
		'maintenance/saneitizeJobs.php',
		'maintenance/updateDYMIndexTemplates.php',
		'maintenance/updateOneSearchIndexConfig.php',
		'maintenance/updateSearchIndexConfig.php',
		'maintenance/updateSuggesterIndex.php',
	]
);

$cfg['enable_class_alias_support'] = true;

return $cfg;
