/*!
 * Grunt file
 *
 * @package CirrusSearch
 */

/* eslint-env node, es6 */

'use strict';

module.exports = function ( grunt ) {
	const conf = grunt.file.readJSON( 'extension.json' );

	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-eslint' );
	grunt.loadNpmTasks( 'grunt-stylelint' );

	grunt.initConfig( {
		eslint: {
			options: {
				cache: true,
				fix: grunt.option( 'fix' )
			},
			all: '.'
		},
		banana: conf.MessagesDirs,
		stylelint: {
			options: {
				cache: true
			},
			all: [
				'**/*.{css,less}',
				'!node_modules/**',
				'!tests/integration/articles/**',
				'!vendor/**'
			]
		}
	} );

	grunt.registerTask( 'test', [ 'eslint', 'banana', 'stylelint' ] );
	grunt.registerTask( 'default', 'test' );
};
