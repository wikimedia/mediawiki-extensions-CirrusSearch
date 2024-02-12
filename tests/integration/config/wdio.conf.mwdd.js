'use strict';

const merge = require( 'deepmerge' ),
	wdioConf = require( './wdio.conf.js' );

// Overwrite default settings
exports.config = merge( wdioConf.config, {
	reporters: [
		'dot',
		[ 'junit',
			{
				outputDir: __dirname + '/../log',
				outputFileFormat: function ( options ) {
					return `results-${ options.cid }-junit.xml`;
				}

			}
		]
	],
	wikis: {
		cirrustest: {
			apiUrl: 'http://cirrustestwiki.mediawiki.mwdd:8080/w/api.php',
			baseUrl: 'http://cirrustestwiki.mediawiki.mwdd:8080'
		},
		commons: {
			apiUrl: 'http://commonswiki.mediawiki.mwdd:8080/w/api.php',
			baseUrl: 'http://commonswiki.mediawiki.mwdd:8080'
		},
		ru: {
			apiUrl: 'http://ruwiki.mediawiki.mwdd:8080/w/api.php',
			baseUrl: 'http://ruwiki.mediawiki.mwdd:8080'
		},
		wikidata: {
			apiUrl: 'http://wikidatawiki.mediawiki.mwdd:8080/w/api.php',
			baseUrl: 'http://wikidatawiki.mediawiki.mwdd:8080'
		}
	}
// overwrite so new reporters override previous instead of merging into combined reporters
}, { arrayMerge: ( dest, source ) => source } );
