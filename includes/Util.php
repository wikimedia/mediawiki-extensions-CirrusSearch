<?php

namespace CirrusSearch;

use GeoData\Coord;
use GeoData\GeoData;
use GeoData\Globe;
use IP;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use PoolCounterWorkViaCallback;
use RequestContext;
use Status;
use Title;
use WebRequest;

/**
 * Random utility functions that don't have a better home
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class Util {
	/**
	 * Cache getDefaultBoostTemplates()
	 *
	 * @var array|null boost templates
	 */
	private static $defaultBoostTemplates = null;

	/**
	 * Get the textual representation of a namespace with underscores stripped, varying
	 * by gender if need be (using Title::getNsText()).
	 *
	 * @param Title $title The page title to use
	 * @return string
	 */
	public static function getNamespaceText( Title $title ) {
		return strtr( $title->getNsText(), '_', ' ' );
	}

	/**
	 * Check if too arrays are recursively the same.  Values are compared with != and arrays
	 * are descended into.
	 *
	 * @param array $lhs one array
	 * @param array $rhs the other array
	 * @return bool are they equal
	 */
	public static function recursiveSame( $lhs, $rhs ) {
		if ( array_keys( $lhs ) != array_keys( $rhs ) ) {
			return false;
		}
		foreach ( $lhs as $key => $value ) {
			if ( !isset( $rhs[ $key ] ) ) {
				return false;
			}
			if ( is_array( $value ) ) {
				if ( !is_array( $rhs[ $key ] ) ) {
					return false;
				}
				if ( !self::recursiveSame( $value, $rhs[ $key ] ) ) {
					return false;
				}
			} else {
				if ( $value != $rhs[ $key ] ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param string $type The pool counter type, such as CirrusSearch-Search
	 * @param bool $isSuccess If the pool counter gave a success, or failed the request
	 * @return string The key used for collecting timing stats about this pool counter request
	 */
	private static function getPoolStatsKey( $type, $isSuccess ) {
		$pos = strpos( $type, '-' );
		if ( $pos !== false ) {
			$type = substr( $type, $pos + 1 );
		}
		$postfix = $isSuccess ? 'successMs' : 'failureMs';
		return "CirrusSearch.poolCounter.$type.$postfix";
	}

	/**
	 * @param float $startPoolWork The time this pool request started, from microtime( true )
	 * @param string $type The pool counter type, such as CirrusSearch-Search
	 * @param bool $isSuccess If the pool counter gave a success, or failed the request
	 * @param callable $callback The function to wrap
	 * @return callable The original callback wrapped to collect pool counter stats
	 */
	private static function wrapWithPoolStats( $startPoolWork, $type, $isSuccess, $callback ) {
		return function () use ( $type, $isSuccess, $callback, $startPoolWork ) {
			MediaWikiServices::getInstance()->getStatsdDataFactory()->timing(
				self::getPoolStatsKey( $type, $isSuccess ),
				intval( 1000 * (microtime( true ) - $startPoolWork) )
			);

			return call_user_func_array( $callback, func_get_args() );
		};
	}

	/**
	 * Wraps the complex pool counter interface to force the single call pattern
	 * that Cirrus always uses.
	 *
	 * @param string $type same as type parameter on PoolCounter::factory
	 * @param \User $user the user
	 * @param callable $workCallback callback when pool counter is acquired.  Called with
	 *  no parameters.
	 * @param callable $errorCallback optional callback called on errors.  Called with
	 *  the error string and the key as parameters.  If left undefined defaults
	 *  to a function that returns a fatal status and logs an warning.
	 * @return mixed
	 */
	public static function doPoolCounterWork( $type, $user, $workCallback, $errorCallback = null ) {
		global $wgCirrusSearchPoolCounterKey;

		// By default the pool counter allows you to lock the same key with
		// multiple types.  That might be useful but it isn't how Cirrus thinks.
		// Instead, all keys are scoped to their type.

		if ( !$user ) {
			// We don't want to even use the pool counter if there isn't a user.
			return $workCallback();
		}
		$perUserKey = md5( $user->getName() );
		$perUserKey = "nowait:CirrusSearch:_per_user:$perUserKey";
		$globalKey = "$type:$wgCirrusSearchPoolCounterKey";
		if ( $errorCallback === null ) {
			$errorCallback = function( $error, $key, $userName ) {
				$forUserName = $userName ? "for {userName} " : '';
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Pool error {$forUserName}on {key}:  {error}",
					array( 'userName' => $userName, 'key' => $key, 'error' => $error )
				);
				return Status::newFatal( 'cirrussearch-backend-error' );
			};
		}
		// wrap some stats collection on the success/failure handlers
		$startPoolWork = microtime( true );
		$workCallback = self::wrapWithPoolStats( $startPoolWork, $type, true, $workCallback );
		$errorCallback = self::wrapWithPoolStats( $startPoolWork, $type, false, $errorCallback );

		$errorHandler = function( $key ) use ( $errorCallback, $user ) {
			return function( Status $status ) use ( $errorCallback, $key, $user ) {
				/** @suppress PhanDeprecatedFunction No good replacements for getErrorsArray */
				$status = $status->getErrorsArray();
				// anon usernames are needed within the logs to determine if
				// specific ips (such as large #'s of users behind a proxy)
				// need to be whitelisted. We do not need this information
				// for logged in users and do not store it.
				$userName = $user->isAnon() ? $user->getName() : '';
				return $errorCallback( $status[ 0 ][ 0 ], $key, $userName );
			};
		};
		$doPerUserWork = function() use ( $type, $globalKey, $workCallback, $errorHandler ) {
			// Now that we have the per user lock lets get the operation lock.
			// Note that this could block, causing the user to wait in line with their lock held.
			$work = new PoolCounterWorkViaCallback( $type, $globalKey, array(
				'doWork' => $workCallback,
				'error' => $errorHandler( $globalKey ),
			) );
			return $work->execute();
		};
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-PerUser', $perUserKey, array(
			'doWork' => $doPerUserWork,
			'error' => function( $status ) use( $errorHandler, $perUserKey, $doPerUserWork ) {
				$errorCallback = $errorHandler( $perUserKey );
				$errorResult = $errorCallback( $status );
				if ( Util::isUserPoolCounterActive() ) {
					return $errorResult;
				} else {
					return $doPerUserWork();
				}
			},
		) );
		return $work->execute();
	}

	/**
	 * @return bool
	 */
	public static function isUserPoolCounterActive() {
		global $wgCirrusSearchBypassPerUserFailure,
			$wgCirrusSearchForcePerUserPoolCounter;

		$ip = RequestContext::getMain()->getRequest()->getIP();
		if ( IP::isInRanges( $ip, $wgCirrusSearchForcePerUserPoolCounter ) ) {
			return true;
		} elseif ( $wgCirrusSearchBypassPerUserFailure ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * @param string $str
	 * @return float
	 */
	public static function parsePotentialPercent( $str ) {
		$result = floatval( $str );
		if ( strpos( $str, '%' ) === false ) {
			return (float) $result;
		}
		return $result / 100;
	}

	/**
	 * Matches $data against $properties to clear keys that no longer exist.
	 * E.g.:
	 * $data = array(
	 *     'title' => "I'm a title",
	 *     'useless' => "I'm useless",
	 * );
	 * $properties = array(
	 *     'title' => 'params-for-title'
	 * );
	 *
	 * Will return:
	 * array(
	 *     'title' => "I'm a title",
	 * )
	 * With the no longer existing 'useless' field stripped.
	 *
	 * We could just use array_intersect_key for this simple example, but it
	 * gets more complex with nested data.
	 *
	 * @param array $data
	 * @param array $properties
	 * @return array
	 */
	public static function cleanUnusedFields( array $data, array $properties ) {
		$data = array_intersect_key( $data, $properties );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $i => $innerValue ) {
					if ( is_array( $innerValue ) && isset( $properties[$key]['properties'] ) ) {
						// go recursive to intersect multidimensional values
						$data[$key][$i] = static::cleanUnusedFields( $innerValue, $properties[$key]['properties'] );
					}

				}
			}
		}

		return $data;
	}

	/**
	 * Parse a message content into an array. This function is generally used to
	 * parse settings stored as i18n messages (see cirrussearch-boost-templates).
	 *
	 * @param string $message
	 * @return string[]
	 */
	public static function parseSettingsInMessage( $message ) {
		$lines = explode( "\n", $message );
		$lines = preg_replace( '/#.*$/', '', $lines ); // Remove comments
		$lines = array_map( 'trim', $lines );          // Remove extra spaces
		$lines = array_filter( $lines );               // Remove empty lines
		return $lines;
	}

	/**
	 * Tries to identify the best redirect by finding the link with the
	 * smallest edit distance between the title and the user query.
	 *
	 * @param string $userQuery the user query
	 * @param array $redirects the list of redirects
	 * @return string the best redirect text
	 */
	public static function chooseBestRedirect( $userQuery, $redirects ) {
		$userQuery = mb_strtolower( $userQuery );
		$len = mb_strlen( $userQuery );
		$bestDistance = INF;
		$best = null;

		foreach( $redirects as $redir ) {
			$text = $redir['title'];
			if ( mb_strlen( $text ) > $len ) {
				$text = mb_substr( $text, 0, $len );
			}
			$text = mb_strtolower( $text );
			$distance = levenshtein( $text, $userQuery );
			if ( $distance == 0 ) {
				return $redir['title'];
			}
			if ( $distance < $bestDistance ) {
				$bestDistance = $distance;
				$best = $redir['title'];
			}
		}
		return $best;
	}

	/**
	 * Test if $string ends with $suffix
	 *
	 * @param string $string string to test
	 * @param string $suffix the suffix
	 * @return boolean true if $string ends with $suffix
	 */
	public static function endsWith( $string, $suffix ) {
		$strlen = strlen( $string );
		$suffixlen = strlen( $suffix );
		if ( $suffixlen > $strlen ) {
			return false;
		}
		return substr_compare( $string, $suffix, $strlen - $suffixlen, $suffixlen ) === 0;
	}

	/**
	 * Set $dest to the true/false from $request->getVal( $name ) if yes/no.
	 *
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 */
	public static function overrideYesNo( &$dest, $request, $name ) {
		$val = $request->getVal( $name );
		if ( $val !== null ) {
			if ( $val === 'yes' ) {
				$dest = true;
			} elseif( $val = 'no' ) {
				$dest = false;
			}
		}
	}

	/**
	 * Set $dest to the numeric value from $request->getVal( $name ) if it is <= $limit
	 * or => $limit if upperLimit is false.
	 *
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 * @param int|null $limit
	 * @param bool $upperLimit
	 */
	public static function overrideNumeric( &$dest, $request, $name, $limit = null, $upperLimit = true ) {
		$val = $request->getVal( $name );
		if ( $val !== null && is_numeric( $val ) ) {
			if ( !isset( $limit ) ) {
				$dest = $val;
			} else if ( $upperLimit && $val <= $limit ) {
				$dest = $val;
			} else if ( !$upperLimit && $val >= $limit ) {
				$dest = $val;
			}
		}
	}

	/**
	 * Get boost templates configured in messages.
	 * @param SearchConfig $config Search config requesting the templates
	 * @return \float[]
	 */
	public static function getDefaultBoostTemplates( SearchConfig $config = null ) {
		// are we on the same wiki?
		if ( is_null( $config ) ) {
			$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
			$local = true;
		} else {
			$local = ( $config->getWikiId() == wfWikiID() );
		}

		$cache = \ObjectCache::getLocalClusterInstance();
		$cacheKey = $cache->makeGlobalKey( 'cirrussearch-boost-templates', $config->getWikiId() );

		if ( $local ) {
			// if we're dealing with local templates
			if ( self::$defaultBoostTemplates !== null ) {
				return self::$defaultBoostTemplates;
			}

			$templates = $cache->getWithSetCallback(
				$cacheKey,
				600,
				function () {
					$source = wfMessage( 'cirrussearch-boost-templates' )->inContentLanguage();
					if( !$source->isDisabled() ) {
						$lines = Util::parseSettingsInMessage( $source->plain() );
						// Now parse the templates
						return Query\BoostTemplatesFeature::parseBoostTemplates( implode( ' ', $lines ) );
					}
					return array();
				}
			);
			self::$defaultBoostTemplates = $templates;
			return $templates;
		}

		// Here we're dealing with boost template from other wiki, try to fetch it if it exists
		// otherwise, don't bother.
		// We won't use static cache for non-local keys.
		$templates = $cache->get( $cacheKey );
		if ( !$templates ) {
			return [];
		}
		return $templates;
	}

	/**
	 * radius, if provided, must have either m or km suffix. Valid formats:
	 *   <title>
	 *   <radius>,<title>
	 *
	 * @param string $text
	 * @return array Three member array with Coordinate object, integer radius
	 *  in meters, and page id to exclude from results.. When invalid the
	 *  Coordinate returned will be null.
	 */
	public static function parseGeoNearbyTitle( $text ) {
		if ( !class_exists( GeoData::class ) ) {
			return array( null, 0, 0 );
		}

		$title = Title::newFromText( $text );
		if ( $title && $title->exists() ) {
			// Default radius if not provided: 5km
			$radius = 5000;
		} else {
			// If the provided value is not a title try to extract a radius prefix
			// from the beginning. If $text has a valid radius prefix see if the
			// remaining text is a valid title to use.
			$pieces = explode( ',', $text, 2 );
			if ( count( $pieces ) !== 2 ) {
				return array( null, 0, 0 );
			}
			$radius = self::parseDistance( $pieces[0] );
			if ( $radius === null ) {
				return array( null, 0, 0 );
			}
			$title = Title::newFromText( $pieces[1] );
			if ( !$title || !$title->exists() ) {
				return array( null, 0, 0 );
			}
		}

		$coord = GeoData::getPageCoordinates( $title );
		if ( !$coord ) {
			return array( null, 0, 0 );
		}

		return array( $coord, $radius, $title->getArticleID() );
	}

	/**
	 * radius, if provided, must have either m or km suffix. Latitude and longitude
	 * must be floats in the domain of [-90:90] for latitude and [-180,180] for
	 * longitude. Valid formats:
	 *   <lat>,<lon>
	 *   <radius>,<lat>,<lon>
	 *
	 * @param string $text
	 * @return array Two member array with Coordinate object, and integer radius
	 *  in meters. When invalid the Coordinate returned will be null.
	 */
	public static function parseGeoNearby( $text ) {
		if ( !class_exists( GeoData::class ) ) {
			return array( null, 0 );
		}

		$pieces = explode( ',', $text, 3 );
		// Default radius if not provided: 5km
		$radius = 5000;
		if ( count( $pieces ) === 3 ) {
			$radius = self::parseDistance( $pieces[0] );
			if ( $radius === null ) {
				return array( null, 0 );
			}
			$lat = $pieces[1];
			$lon = $pieces[2];
		} elseif ( count( $pieces ) === 2 ) {
			$lat = $pieces[0];
			$lon = $pieces[1];
		} else {
			return array( null, 0 );
		}

		$globe = new Globe( 'earth' );
		if ( !$globe->coordinatesAreValid( $lat, $lon ) ) {
			return array( null, 0 );
		}

		return array(
			new Coord( floatval( $lat ), floatval( $lon ), $globe->getName() ),
			$radius,
		);
	}

	/**
	 * @param string $distance
	 * @param int $default
	 * @return int|null Parsed distance in meters, or null if unparsable
	 */
	public static function parseDistance( $distance ) {
		if ( !preg_match( '/^(\d+)(m|km|mi|ft|yd)$/', $distance, $matches ) ) {
			return null;
		}

		$scale = array(
			'm' => 1,
			'km' => 1000,
			// Supported non-SI units, and their conversions, sourced from
			// https://en.wikipedia.org/wiki/Unit_of_length#Imperial.2FUS
			'mi' => 1609.344,
			'ft' => 0.3048,
			'yd' => 0.9144,
		);

		return max( 10, (int) round( $matches[1] * $scale[$matches[2]] ) );
	}

	/**
	 * Strip question marks from queries, according to the defined stripping
	 * level, defined by $wgCirrusSearchStripQuestionMarks. Strip all ?s, those
	 * at word breaks, or only string-final. Ignore queries that are all
	 * punctuation or use insource. Don't remove escaped \?s, but unescape them.
	 * ¿ is not :punct:, hence $more_punct.
	 *
	 * @param string $term
	 * @param string $strippingLevel
	 * @return string modified term, based on strippingLevel
	 */
	public static function stripQuestionMarks( $term, $strippingLevel ) {
		// strip question marks
		$more_punct = "[¿]";
		if ( strpos( $term, 'insource:' ) === false &&
			preg_match( "/^([[:punct:]]|\s|$more_punct)+$/", $term ) === 0
		) {
			if ( $strippingLevel === 'final' ) {
				// strip only query-final question marks that are not escaped
				$term = preg_replace( "/((?<!\\\\)\?|\s)+$/", '', $term );
				$term = preg_replace( '/\\\\\?/', '?', $term );
			} elseif ( $strippingLevel === 'break' ) {
				//strip question marks at word boundaries
				$term = preg_replace( '/(?<!\\\\)(\?)+(\PL|$)/', '$2', $term );
				$term = preg_replace( '/\\\\\?/', '?', $term );
			} elseif ( $strippingLevel === 'all' ) {
				//strip all unescapred question marks
				$term = preg_replace( '/(?<!\\\\)(\?)+/', ' ', $term );
				$term = preg_replace( '/\\\\\?/', '?', $term );
			}
		}
	return $term;
	}
}
