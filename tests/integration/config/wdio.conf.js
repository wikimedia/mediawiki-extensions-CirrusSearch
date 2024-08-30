'use strict';

const child_process = require( 'child_process' ),
	path = require( 'path' ),
	fs = require( 'fs' );

function relPath( foo ) {
	return path.resolve( __dirname, '../..', foo );
}

function fallback( ...args ) {
	for ( const arg of args ) {
		if ( arg !== undefined ) {
			return arg;
		}
	}
	return undefined;
}

let forkedTracker;
let unixSocket;

exports.config = {

	//
	// ======
	//
	// ======
	// Custom
	// ======
	// Define any custom variables.
	// Example:
	// username: 'Admin',
	// Use if from tests with:
	// browser.options.username
	username: fallback( process.env.MEDIAWIKI_USER, 'Admin' ),
	password: fallback( process.env.MEDIAWIKI_PASSWORD, 'vagrant' ),
	botPassword: fallback( process.env.MEDIAWIKI_BOT_PASSWORD, 'vagrant' ),
	baseUrl: 'http://cirrustest.wiki.local.wmftest.net:8080',
	wikis: {
		default: 'cirrustest',
		cirrustest: {
			username: 'Admin',
			password: fallback( process.env.MEDIAWIKI_PASSWORD, 'vagrant' ),
			botPassword: fallback( process.env.MEDIAWIKI_CIRRUSTEST_BOT_PASSWORD, process.env.MEDIAWIKI_BOT_PASSWORD, 'vagrant' ),
			apiUrl: 'http://cirrustest.wiki.local.wmftest.net:8080/w/api.php',
			baseUrl: 'http://cirrustest.wiki.local.wmftest.net:8080'
		},
		commons: {
			username: 'Admin',
			password: fallback( process.env.MEDIAWIKI_PASSWORD, 'vagrant' ),
			botPassword: fallback( process.env.MEDIAWIKI_COMMONS_BOT_PASSWORD, process.env.MEDIAWIKI_BOT_PASSWORD, 'vagrant' ),
			apiUrl: 'http://commons.wiki.local.wmftest.net:8080/w/api.php',
			baseUrl: 'http://commons.wiki.local.wmftest.net:8080'
		},
		ru: {
			username: 'Admin',
			password: fallback( process.env.MEDIAWIKI_PASSWORD, 'vagrant' ),
			botPassword: fallback( process.env.MEDIAWIKI_RU_BOT_PASSWORD, process.env.MEDIAWIKI_BOT_PASSWORD, 'vagrant' ),
			apiUrl: 'http://ru.wiki.local.wmftest.net:8080/w/api.php',
			baseUrl: 'http://ru.wiki.local.wmftest.net:8080'
		},
		wikidata: {
			username: 'Admin',
			password: fallback( process.env.MEDIAWIKI_PASSWORD, 'vagrant' ),
			botPassword: fallback( process.env.MEDIAWIKI_RU_BOT_PASSWORD, process.env.MEDIAWIKI_BOT_PASSWORD, 'vagrant' ),
			apiUrl: 'http://wikidata.wiki.local.wmftest.net:8080/w/api.php',
			baseUrl: 'http://wikidata.wiki.local.wmftest.net:8080'
		},
		beta: {},
		test2: {},
		integration: {},
		cindy: {},
		searchdemo: {}
	},
	//
	// ======
	// Sauce Labs
	// ======
	//
	// services: [ 'sauce' ],
	// user: process.env.SAUCE_USERNAME,
	// key: process.env.SAUCE_ACCESS_KEY,
	//
	// ==================
	// Specify Test Files
	// ==================
	// Define which test specs should run. The pattern is relative to the directory
	// from which `wdio` was called. Notice that, if you are calling `wdio` from an
	// NPM script (see https://docs.npmjs.com/cli/run-script) then the current working
	// directory is where your package.json resides, so `wdio` will be called from there.
	//
	specs: [
		process.env.CIRRUS_FEATURES || relPath( './integration/features/*.feature' )
	],
	cucumberOpts: {
		tagsInTitle: true,
		timeout: 60000,
		tagExpression: process.env.CIRRUS_TAGS,
		require: [
			relPath( './integration/features/support/world.js' ),
			relPath( './integration/features/support/hooks.js' ),
			relPath( './integration/features/step_definitions/page_step_helpers.js' ),
			relPath( './integration/features/step_definitions/page_steps.js' ),
			relPath( './integration/features/step_definitions/search_steps.js' )
		]
	},
	// Patterns to exclude.
	exclude: [
	// 'path/to/excluded/files'
	],
	//
	// ============
	// Capabilities
	// ============
	// Define your capabilities here. WebdriverIO can run multiple capabilities at the same
	// time. Depending on the number of capabilities, WebdriverIO launches several test
	// sessions. Within your capabilities you can overwrite the spec and exclude options in
	// order to group specific specs to a specific capability.
	//
	// First, you can define how many instances should be started at the same time. Let's
	// say you have 3 different capabilities (Chrome, Firefox, and Safari) and you have
	// set maxInstances to 1; wdio will spawn 3 processes. Therefore, if you have 10 spec
	// files and you set maxInstances to 10, all spec files will get tested at the same time
	// and 30 processes will get spawned. The property handles how many capabilities
	// from the same test should run tests.
	//
	maxInstances: 1,
	//
	// If you have trouble getting all important capabilities together, check out the
	// Sauce Labs platform configurator - a great tool to configure your capabilities:
	// https://docs.saucelabs.com/reference/platforms-configurator
	//
	// For Chrome/Chromium https://sites.google.com/a/chromium.org/chromedriver/capabilities
	capabilities: [ {
		// maxInstances can get overwritten per capability. So if you have an in-house Selenium
		// grid with only 5 firefox instances available you can make sure that not more than
		// 5 instances get started at a time.
		maxInstances: 8,
		//
		browserName: 'chrome',
		// Since Chrome v57 https://bugs.chromium.org/p/chromedriver/issues/detail?id=1625
		'goog:chromeOptions': {
			args: [ '--headless' ]
		}
	} ],
	//
	// ===================
	// Test Configurations
	// ===================
	// Define all options that are relevant for the WebdriverIO instance here
	//
	// Level of logging verbosity: trace | debug | info | warn | error | silent
	logLevel: 'warn',
	//
	// Enables colors for log output.
	coloredLogs: true,
	//
	// Saves a screenshot to a given path if a command fails.
	screenshotPath: './log/',
	//
	// Default timeout for all waitFor* commands.
	waitforTimeout: 20000,
	//
	// Default timeout in milliseconds for request
	// if Selenium Grid doesn't send response
	connectionRetryTimeout: 90000,
	//
	// Default request retries count
	connectionRetryCount: 3,
	//
	// Initialize the browser instance with a WebdriverIO plugin. The object should have the
	// plugin name as key and the desired plugin options as properties. Make sure you have
	// the plugin installed before running any tests. The following plugins are currently
	// available:
	// WebdriverCSS: https://github.com/webdriverio/webdrivercss
	// WebdriverRTC: https://github.com/webdriverio/webdriverrtc
	// Browserevent: https://github.com/webdriverio/browserevent
	// plugins: {
	//     webdrivercss: {
	//         screenshotRoot: 'my-shots',
	//         failedComparisonsRoot: 'diffs',
	//         misMatchTolerance: 0.05,
	//         screenWidth: [320,480,640,1024]
	//     },
	//     webdriverrtc: {},
	//     browserevent: {}
	// },
	//
	// Test runner services
	// Services take over a specific job you don't want to take care of. They enhance
	// your test setup with almost no effort. Unlike plugins, they don't add new
	// commands. Instead, they hook themselves up into the test process.
	// services: [],//
	// Framework you want to run your specs with.
	// The following are supported: Mocha, Jasmine, and Cucumber
	// see also: http://webdriver.io/guide/testrunner/frameworks.html
	//
	// Make sure you have the wdio adapter package for the specific framework installed
	// before running any tests.
	framework: 'cucumber',

	// Test reporter for stdout.
	// The only one supported by default is 'dot'
	// see also: http://webdriver.io/guide/testrunner/reporters.html
	reporters: [ 'spec' ],
	//
	// Options to be passed to Mocha.
	// See the full list at http://mochajs.org/
	mochaOpts: {
		ui: 'bdd',
		timeout: 20000
	},
	//
	// =====
	// Hooks
	// =====
	// WebdriverIO provides several hooks you can use to interfere with the test process in order to enhance
	// it and to build services around it. You can either apply a single function or an array of
	// methods to it. If one of them returns with a promise, WebdriverIO will wait until that promise got
	// resolved to continue.
	//
	// unix socket path for tag tracker
	trackerPath: '/tmp/cirrussearch-integration-tagtracker',
	//
	// Gets executed once before all workers get launched.
	onPrepare: function ( config ) {
		forkedTracker = child_process.fork( relPath( './integration/lib/tracker.js' ) );
		unixSocket = config.trackerPath;
		return new Promise( ( resolve, reject ) => {
			forkedTracker.on( 'message', ( msg ) => {
				if ( msg.initialized ) {
					console.log( 'initialized tracker' );
					resolve();
				} else {
					console.log( 'failed to init tracker' );
					console.log( msg.error );
					reject( msg.error );
				}
			} );
			forkedTracker.send( { config: config } );
		} );
	},
	//
	// Gets executed before test execution begins. At this point you can access all global
	// variables, such as `browser`. It is the perfect place to define custom commands.
	// before: function (capabilities, specs) {
	// },
	//
	// Hook that gets executed before the suite starts
	// beforeSuite: function (suite) {
	// },
	//
	// Hook that gets executed _before_ a hook within the suite starts (e.g. runs before calling
	// beforeEach in Mocha)
	// beforeHook: function () {
	// },
	//
	// Hook that gets executed _after_ a hook within the suite starts (e.g. runs after calling
	// afterEach in Mocha)
	//
	// Function to be executed before a test (in Mocha/Jasmine) or a step (in Cucumber) starts.
	// beforeTest: function (test) {
	// },
	//
	// Runs before a WebdriverIO command gets executed.
	// beforeCommand: function (commandName, args) {
	// },
	//
	// Runs after a WebdriverIO command gets executed
	// afterCommand: function (commandName, args, result, error) {
	// },
	//
	// Function to be executed after a test (in Mocha/Jasmine) or a step (in Cucumber) starts.
	// from https://github.com/webdriverio/webdriverio/issues/269#issuecomment-306342170
	afterTest: async function ( test ) {
		// if test passed, ignore, else take and save screenshot
		if ( test.passed ) {
			return;
		}
		// get current test title and clean it, to use it as file name
		const filename = encodeURIComponent( test.title.replace( /\s+/g, '-' ) );
		// build file path
		const filePath = this.screenshotPath + filename + '.png';
		// save screenshot
		await browser.saveScreenshot( filePath );
		console.log( '\n\tScreenshot location:', filePath, '\n' );
	},
	//
	// Hook that gets executed after the suite has ended
	// afterSuite: function (suite) {
	// },
	//
	// Gets executed after all tests are done. You still have access to all global variables from
	// the test.
	// after: function (result, capabilities, specs) {
	// },
	//
	// Gets executed after all workers got shut down and the process is about to exit. It is not
	// possible to defer the end of the process using a promise.
	onComplete: function () {
		console.log( 'Attempting shutdown of forked tracker' );
		// TODO: Is this method being called a guarantee, or should we handle signals to be sure?
		try {
			forkedTracker.send( { exit: true } );
		} catch ( err ) {
			console.log( `Failed to send exit signal to tracker: ${ err }` );
			// Force unlinking the socket
			fs.unlinkSync( unixSocket );
		}
	}
};
