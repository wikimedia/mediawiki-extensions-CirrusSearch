'use strict';

const merge = require( 'deepmerge' ),
	wdioConf = require( './wdio.conf.js' );

// Overwrite default settings
exports.config = merge( wdioConf.config, {
	reporters: [
		'spec',
		[ 'junit',
			{
				outputDir: __dirname + '/../log',
				outputFileFormat: function ( options ) {
					return `results-${ options.cid }-junit.xml`;
				}

			}
		]
	],
	baseUrl: 'https://cirrustest-' + process.env.MWV_LABS_HOSTNAME + '.wmcloud.org',
	wikis: {
		cirrustest: {
			apiUrl: 'https://cirrustest-' + process.env.MWV_LABS_HOSTNAME + '.wmcloud.org/w/api.php',
			baseUrl: 'https://cirrustest-' + process.env.MWV_LABS_HOSTNAME + '.wmcloud.org'
		},
		commons: {
			apiUrl: 'https://commons-' + process.env.MWV_LABS_HOSTNAME + '.wmcloud.org/w/api.php',
			baseUrl: 'https://commons-' + process.env.MWV_LABS_HOSTNAME + '.wmcloud.org'
		},
		ru: {
			apiUrl: 'https://ru-' + process.env.MWV_LABS_HOSTNAME + '.wmcloud.org/w/api.php',
			baseUrl: 'https://ru-' + process.env.MWV_LABS_HOSTNAME + '.wmcloud.org'
		}
	}
// overwrite so new reporters override previous instead of merging into combined reporters
}, { arrayMerge: ( dest, source ) => source } );
