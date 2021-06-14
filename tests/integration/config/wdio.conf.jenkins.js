'use strict';

const merge = require( 'deepmerge' ),
	wdioConf = require( './wdio.conf.js' );

// Overwrite default settings
exports.config = merge( wdioConf.config, {
	screenshotPath: '../log/',
	baseUrl: process.env.MW_SERVER + process.env.MW_SCRIPT_PATH,

	reporters: [
		'spec',
		[ 'junit',
			{
				outputDir: __dirname + '/../log',
				outputFileFormat: function ( options ) {
					return `results-${options.cid}-junit.xml`;
				}

			}
		]
	]
} );
