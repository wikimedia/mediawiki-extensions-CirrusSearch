/*jshint esversion: 6, node:true */

var {defineSupportCode} = require( 'cucumber' );
var Promise = require( 'bluebird' );

defineSupportCode( function( { After, Before } ) {
	let BeforeOnce = function ( options, fn ) {
		Before( options, Promise.coroutine( function* () {
			let response = yield this.tags.check( options.tags );
			if ( response === 'new' ) {
				yield fn.call( this );
				yield this.tags.complete( options.tags );
			}
		} ) );
	};

	let waitForBatch = Promise.coroutine( function* ( wiki, batchJobs ) {
		let stepHelpers;
		if ( batchJobs === undefined ) {
			stepHelpers = this.stepHelpers;
			batchJobs = wiki;
		} else {
			stepHelpers = this.stepHelpers.onWiki( wiki );
		}

		if ( Array.isArray( batchJobs ) ) {
			for ( let job of batchJobs ) {
				yield stepHelpers.waitForOperation( job[0], job[1] );
			}
		} else {
			for ( let operation in batchJobs ) {
				let operationJobs = batchJobs[operation];
				if ( Array.isArray( operationJobs ) ) {
					for ( let title of operationJobs ) {
						yield stepHelpers.waitForOperation( operation, title );
					}
				} else {
					for ( let title in operationJobs ) {
						yield stepHelpers.waitForOperation( operation, title );
					}
				}
			}
		}
	} );

	let runBatchFn = ( wiki, batchJobs ) => Promise.coroutine( function* () {
		let client;
		if ( batchJobs === undefined ) {
			batchJobs = wiki;
			client = yield this.onWiki();
		} else {
			client = yield this.onWiki( wiki );
		}

		yield client.batch( batchJobs, 'CirrusSearch integration test edit' );
		yield waitForBatch.call( this, batchJobs );
	} );

	BeforeOnce( { tags: "@clean" }, runBatchFn( {
		delete: [ 'DeleteMeRedirect' ]
	} ) );

	BeforeOnce( { tags: "@prefix" }, runBatchFn( {
		edit: {
			"L'Oréal": "L'Oréal",
			"Jean-Yves Le Drian": "Jean-Yves Le Drian"
		}
	} ) );

	BeforeOnce( { tags: "@redirect", timeout: 60000 }, runBatchFn( {
		edit: {
			"SEO Redirecttest": "#REDIRECT [[Search Engine Optimization Redirecttest]]",
			"Redirecttest Yikes": "#REDIRECT [[Redirecttest Yay]]",
			"User_talk:SEO Redirecttest": "#REDIRECT [[User_talk:Search Engine Optimization Redirecttest]]",
			"Seo Redirecttest": "Seo Redirecttest",
			"Search Engine Optimization Redirecttest": "Search Engine Optimization Redirecttest",
			"Redirecttest Yay": "Redirecttest Yay",
			"User_talk:Search Engine Optimization Redirecttest": "User_talk:Search Engine Optimization Redirecttest",
			"PrefixRedirectRanking 1": "PrefixRedirectRanking 1",
			"LinksToPrefixRedirectRanking 1": "[[PrefixRedirectRanking 1]]",
			"TargetOfPrefixRedirectRanking 2": "TargetOfPrefixRedirectRanking 2",
			"PrefixRedirectRanking 2": "#REDIRECT [[TargetOfPrefixRedirectRanking 2]]"
		}
	} ) );

	BeforeOnce( { tags: "@accent_squashing" }, runBatchFn( {
		edit: {
			"Áccent Sorting": "Áccent Sorting",
			"Accent Sorting": "Accent Sorting"
		}
	} ) );

	BeforeOnce( { tags: "@accented_namespace" }, runBatchFn( {
		edit: {
			"Mó:Test": "some text"
		}
	} ) );

	BeforeOnce( { tags: "@setup_main or @filters or @prefix or @bad_syntax or @wildcard or @exact_quotes or @phrase_prefix", timeout: 60000 }, runBatchFn( {
		edit: {
			"Template:Template Test": "pickles [[Category:TemplateTagged]]",
			"Catapult/adsf": "catapult subpage [[Catapult]]",
			"Links To Catapult": "[[Catapult]]",
			"Catapult": "♙ asdf [[Category:Weaponry]]",
			"Amazing Catapult": "test [[Catapult]] [[Category:Weaponry]]",
			"Category:Weaponry": "Weaponry refers to any items designed or used to attack and kill or destroy other people and property.",
			"Two Words": "ffnonesenseword catapult {{Template_Test}} anotherword [[Category:TwoWords]] [[Category:Categorywith Twowords]] [[Category:Categorywith \" Quote]]",
			"AlphaBeta": "[[Category:Alpha]] [[Category:Beta]]",
			"IHaveATwoWordCategory": "[[Category:CategoryWith ASpace]]",
			"Functional programming": "Functional programming is referential transparency.",
			"वाङ्मय": "वाङ्मय",
			"वाङ्\u200dमय": "वाङ्\u200dमय",
			"वाङ्\u200cमय": "वाङ्\u200cमय",
			"ChangeMe": "foo",
			"Wikitext": "{{#tag:somebug}}",
			"Page with non ascii letters": "ἄνθρωπος, широкий"
		}
	} ) );

	BeforeOnce( { tags: "@setup_main or @prefix or @bad_syntax" }, runBatchFn( {
		// TODO: File upload
		// And a file named File:Savepage-greyed.png exists with contents Savepage-greyed.png and description Screenshot, for test purposes, associated with https://bugzilla.wikimedia.org/show_bug.cgi?id=52908 .
		edit: {
			"Rdir": "#REDIRECT [[Two Words]]",
			"IHaveAVideo": "[[File:How to Edit Article in Arabic Wikipedia.ogg|thumb|267x267px]]",
			"IHaveASound": "[[File:Serenade for Strings -mvt-1- Elgar.ogg]]"
		}
	} ) );

	BeforeOnce( { tags: "@setup_main or @prefix or @go or @bad_syntax" }, runBatchFn( {
		edit: {
			"África": "for testing"
		}
	} ) );

	BeforeOnce( { tags: "@boost_template" }, runBatchFn( {
		edit: {
			"Template:BoostTemplateHigh": "BoostTemplateTest",
			"Template:BoostTemplateLow": "BoostTemplateTest",
			"NoTemplates BoostTemplateTest": "nothing important",
			"HighTemplate": "{{BoostTemplateHigh}}",
			"LowTemplate": "{{BoostTemplateLow}}",
		}
	} ) );

	// This needs to be the *last* hook added. That gives us some hope that everything
	// else is inside elasticsearch by the time cirrus-suggest-index runs and builds
	// the completion suggester
	BeforeOnce( { tags: "@suggest", timeout: 60000 }, Promise.coroutine( function* () {
		let client = yield this.onWiki();
		let batchJobs = {
			edit: {
				"X-Men": "The X-Men are a fictional team of superheroes",
				"Xavier: Charles": "Professor Charles Francis Xavier (also known as Professor X) is the founder of [[X-Men]]",
				"X-Force": "X-Force is a fictional team of of [[X-Men]]",
				"Magneto": "Magneto is a fictional character appearing in American comic books",
				"Max Eisenhardt": "#REDIRECT [[Magneto]]",
				"Eisenhardt, Max": "#REDIRECT [[Magneto]]",
				"Magnetu": "#REDIRECT [[Magneto]]",
				"Ice": "It's cold.",
				"Iceman": "Iceman (Robert \"Bobby\" Drake) is a fictional superhero appearing in American comic books published by Marvel Comics and is...",
				"Ice Man (Marvel Comics)": "#REDIRECT [[Iceman]]",
				"Ice-Man (comics books)": "#REDIRECT [[Iceman]]",
				"Ultimate Iceman": "#REDIRECT [[Iceman]]",
				"Électricité": "This is electicity in french.",
				"Elektra": "Elektra is a fictional character appearing in American comic books published by Marvel Comics.",
				"Help:Navigation": "When viewing any page on MediaWiki...",
				"V:N": "#REDIRECT [[Help:Navigation]]",
				"Z:Navigation": "#REDIRECT [[Help:Navigation]]",
				"Venom": "Venom: or the Venom Symbiote: is a fictional supervillain appearing in American comic books published by Marvel Comics",
				"Sam Wilson": "Warren Kenneth Worthington III: originally known as Angel and later as Archangel: ... Marvel Comics like [[Venom]]. {{DEFAULTSORTKEY:Wilson: Sam}}",
				"Zam Wilson": "#REDIRECT [[Sam Wilson]]",
				"The Doors": "The Doors were an American rock band formed in 1965 in Los Angeles.",
				"Hyperion Cantos/Endymion": "Endymion is the third science fiction novel by Dan Simmons.",
				"はーい": "makes sure we do not fail to index empty tokens (T156234)"
			}
		};
		yield client.batch( batchJobs );
		yield waitForBatch.call( this, batchJobs );
		yield client.request( {
			action: 'cirrus-suggest-index'
		} );
	} ) );

} );
