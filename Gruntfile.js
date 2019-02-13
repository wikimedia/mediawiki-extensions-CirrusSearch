/*!
 * Grunt file
 *
 * @package CirrusSearch
 */

/* eslint-env node, es6 */

const path = require( 'path' );

module.exports = function ( grunt ) {
	var WebdriverIOconfigFile;

	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-eslint' );
	grunt.loadNpmTasks( 'grunt-jsonlint' );
	grunt.loadNpmTasks( 'grunt-stylelint' );
	grunt.loadNpmTasks( 'grunt-webdriver' );

	if ( process.env.JENKINS_HOME ) {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.jenkins.js';
	} else if ( process.env.MWV_LABS_HOSTNAME ) {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.mwvlabs.js';
	} else {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.js';
	}

	grunt.initConfig( {
		eslint: {
			options: {
				reportUnusedDisableDirectives: true
			},
			all: [
				'**/*.js',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		banana: {
			all: [
				'i18n/',
				'i18n/api/'
			]
		},
		jsonlint: {
			all: [
				'**/*.json',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		stylelint: {
			all: [
				'**/*.css',
				'**/*.less',
				'!node_modules/**',
				'!tests/integration/articles/**',
				'!vendor/**'
			]
		},
		// Configure WebdriverIO Node task
		webdriver: {
			test: {
				configFile: WebdriverIOconfigFile,
				cucumberOpts: {
					tagExpression: ( () => grunt.option( 'tags' ) )()
				},
				maxInstances: ( () => {
					let max = grunt.option( 'maxInstances' );
					return max ? parseInt( max, 10 ) : 1;
				} )(),
				spec: ( () => {
					let spec = grunt.option( 'spec' );
					if ( !spec ) {
						return undefined;
					}
					if ( spec[ 0 ] === '/' ) {
						return spec;
					}
					return path.join( __dirname, 'tests/integration/features', spec );
				} )()
			}
		}
	} );

	grunt.registerTask( 'test', [ 'eslint', 'jsonlint', 'banana', 'stylelint' ] );
	grunt.registerTask( 'default', 'test' );
};
