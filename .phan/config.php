<?php

$directoryList = [];
$excludeAnalysisDirectoryList = [];

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = realpath( __DIR__ . '/../../..' );
}

$cirrusDirs = ['includes', 'maintenance', 'profiles', '.phan/stubs'];
$otherDirs = [
	'includes', 'vendor', 'maintenance', 'languages',
	'extensions/Elastica', 'extensions/GeoData', 'extensions/BetaFeatures',
	'extensions/CirrusSearch/vendor',
];
foreach ( $cirrusDirs as $dir ) {
	$directoryList[] = "$IP/extensions/CirrusSearch/$dir";
}
foreach ( $otherDirs as $dir ) {
	$fullpath = "$IP/$dir";
	$directoryList[] = $fullpath;
	$excludeAnalysisDirectoryList[] = $fullpath;
}

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 */
return [
	// A list of directories that should be parsed for class and
	// method information. After excluding the directories
	// defined in exclude_analysis_directory_list, the remaining
	// files will be statically analyzed for errors.
	//
	// Thus, both first-party and third-party code being used by
	// your application should be included in this list.
	'directory_list' => $directoryList,

	// A directory list that defines files that will be excluded
	// from static analysis, but whose class and method
	// information should be included.
	//
	// Generally, you'll want to include the directories for
	// third-party code (such as "vendor/") in this list.
	//
	// n.b.: If you'd like to parse but not analyze 3rd
	//	   party code, directories containing that code
	//	   should be added to the `directory_list` as
	//	   to `excluce_analysis_directory_list`.
	"exclude_analysis_directory_list" => $excludeAnalysisDirectoryList,

	// Backwards Compatibility Checking. This is slow
	// and expensive, but you should consider running
	// it before upgrading your version of PHP to a
	// new version that has backward compatibility
	// breaks.
	'backward_compatibility_checks' => false,

	// Run a quick version of checks that takes less
	// time at the cost of not running as thorough
	// an analysis. You should consider setting this
	// to true only when you wish you had more issues
	// to fix in your code base.
	'quick_mode' => false,

	// If enabled, check all methods that override a
	// parent method to make sure its signature is
	// compatible with the parent's. This check
	// can add quite a bit of time to the analysis.
	'analyze_signature_compatibility' => true,

	// The minimum severity level to report on. This can be
	// set to Issue::SEVERITY_LOW, Issue::SEVERITY_NORMAL or
	// Issue::SEVERITY_CRITICAL. Setting it to only
	// critical issues is a good place to start on a big
	// sloppy mature code base.
	'minimum_severity' => 0,

	// If true, missing properties will be created when
	// they are first seen. If false, we'll report an
	// error message if there is an attempt to write
	// to a class property that wasn't explicitly
	// defined.
	'allow_missing_properties' => false,

	// Allow null to be cast as any type and for any
	// type to be cast to null. Setting this to false
	// will cut down on false positives.
	'null_casts_as_any_type' => false,

	// If enabled, scalars (int, float, bool, string, null)
	// are treated as if they can cast to each other.
	'scalar_implicit_cast' => false,

	// If true, seemingly undeclared variables in the global
	// scope will be ignored. This is useful for projects
	// with complicated cross-file globals that you have no
	// hope of fixing.
	'ignore_undeclared_variables_in_global_scope' => true,

	// Add any issue types (such as 'PhanUndeclaredMethod')
	// to this black-list to inhibit them from being reported.
	'suppress_issue_types' => [
	],

	// If empty, no filter against issues types will be applied.
	// If this white-list is non-empty, only issues within the list
	// will be emitted by Phan.
	'whitelist_issue_types' => [
	],
];
