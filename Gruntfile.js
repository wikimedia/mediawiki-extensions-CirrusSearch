/*jshint esversion: 6, node:true */
/*!
 * Grunt file
 *
 * @package CirrusSearch
 */

const path = require( 'path' );

/*jshint node:true */
module.exports = function ( grunt ) {
	grunt.loadNpmTasks( 'grunt-contrib-jshint' );
	grunt.loadNpmTasks( 'grunt-jsonlint' );
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-stylelint' );
	grunt.loadNpmTasks( 'grunt-webdriver' );

	var WebdriverIOconfigFile;

	if ( process.env.JENKINS_HOME ) {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.jenkins.js';
	} else if ( process.env.MWV_LABS_HOSTNAME ) {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.mwvlabs.js';
	} else {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.js';
	}

	grunt.initConfig( {
		jshint: {
			options: {
				jshintrc: true
			},
			all: [
				'**/*.js',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		banana: {
			all: [
				'i18n/'
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
				'!tests/browser/articles/**',
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
					if ( spec[0] === '/' ) {
						return spec;
					}
					return path.join(__dirname, 'tests/integration/features', spec);
				} )()
			}
		}
	} );

	grunt.registerTask( 'test', [ 'jshint', 'jsonlint', 'banana', 'stylelint' ] );
	grunt.registerTask( 'default', 'test' );
};
