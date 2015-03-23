/*global mw:true */
( function ( $ ) {
	'use strict';

	var isLoggingEnabled = mw.config.get( 'wgCirrusSearchEnableSearchLogging' ),
		// For 1 in a 1000 users the metadata about interaction
		// with the search form (absent search terms) is event logged.
		// See https://meta.wikimedia.org/wiki/Schema:Search
		isSampled = Math.random() < 1 / 1000,
		defaults,
		sessionStartTime;

	/**
	 * Generate a random token
	 * @return {String}
	 */
	function getRandomToken() {
		return mw.user.generateRandomSessionId() + ( new Date() ).getTime().toString();
	}

	if ( isLoggingEnabled && isSampled ) {
		defaults = {
			platform: 'desktop',
			userSessionToken: getRandomToken(),
			searchSessionToken: getRandomToken()
		};

		mw.trackSubscribe( 'mediawiki.searchSuggest', function ( topic, data ) {
			var loggingData = {
				action: data.action
			};

			if ( data.action === 'session-start' ) {
				// update session token if it's a new search
				defaults.searchSessionToken = getRandomToken();
				sessionStartTime = this.timeStamp;
			} else if ( data.action === 'impression-results' ) {
				loggingData.numberOfResults = data.numberOfResults;
				loggingData.resultSetType = data.resultSetType;
				loggingData.timeToDisplayResults = Math.round( this.timeStamp - sessionStartTime );
			} else if ( data.action === 'click-result' ) {
				loggingData.clickIndex = data.clickIndex;
				loggingData.numberOfResults = data.numberOfResults;
			}
			loggingData.timeOffsetSinceStart = Math.round( this.timeStamp - sessionStartTime ) ;
			$.extend( loggingData, defaults );
			mw.eventLog.logEvent( 'Search', loggingData );
		} );
	}
}( jQuery ) );
