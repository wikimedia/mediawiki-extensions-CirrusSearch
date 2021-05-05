# User Testing (AB)

CirrusSearch implements an AB testing system that can deploy a single active
test at a time which will separate users into multiple buckets and give each
bucket a separate configuration of the SearchEngine. Users are divided equally
between all configured buckets of the test.

## Configuration

### wgCirrusSearchUserTesting

Defines the set of test configurations available for use with the test name
as the array key.

Per test configuration options:

* globals - Optional map from global variable name to value to set for all
            requests participating in the test.
* buckets - A map from bucket name to bucket configuration.

Per bucket configuration options:

* globals - Optional map from global variable name to value to set for all
            requests in this bucket. Per-bucket globals override per-test globals.

Example configuration:

  $wgCirrusSearchUserTesting = [
      'some_test' => [
          'buckets' => [
              'a' => [
                  // control bucket, retain defaults
              ],
              'b' => [
                  'globals' => [
                      'wgCirrusSearchRescoreProfile' => 'classic',
                  ],
              ],
              ...
          ],
      ],
      ...
  ];

### wgCirrusSearchActiveTest

Defines the test which will be used for auto enrollment. Must either be an
array key from `wgCirrusSearchUserTesting` or null. The separation of this
value from the primary configuration allows deploying a single configuration to
all wikis in a fleet but only activating tests intended for that wiki.

## Assumptions

Design decisions within user testing make some fairly broad assumptions about
the testing methodology, caching used in MediaWiki, and flow of users through
the UI. In particular:

* Assumes Special:Search does not allow http caching. As of May 2021 this is
  true, but it could always change.

* Assumes some API requests, particularly for autocomplete, are cachable.

* Assumes the typical starting point of a search session is either the skin
  autocomplete (go box), or by following a link (potentially via browser
  navigation bar) to Special:Search.

* Assumes it is more acceptable for the first few autocomplete requests in a
  session to be missing the test treatment than the first full-text request.

## Test Enrollment

Test enrollment is the process through which requests are assigned specific
test buckets and SearchEngine configurations. Test enrollment is automatic for
`index.php` requests and requires explicit triggers for all other entry points.
API requests in particular can be cacheable and thus must not get automatic
enrollment. To ensure test buckets get different responses they must have
different request URLs.

### Trigger

CirrusSearch exports a value, specifically `wgCirrusSearchActiveUserTest` or
more simply the trigger, which reports the enrollment decision for the current
user. The trigger value is in the form `<test name>:<bucket>`. This value is
available from the on-page js config (`mw.config.get`) of Special:Search, or it
can be requested from the `cirrus-config-dump` API call. This will be the empty
string when no test is running.

### Automatic Enrollment

Automatic enrollment assigns a test bucket to every incoming `index.php` request
and is designed to ensure sessions starting outside MediaWiki, such as in a
browser search bar, are still enrolled in a test from the first search request.
Automatic enrollment only applies to `index.php` requests and is turned on by
setting `wgCirrusSearchActiveTest` to an array key of `wgCirrusSearchUserTesting`.

A secondary goal of automatic enrollment is to avoid including the test trigger
in any URL a user might want to copy and provide to someone else. We want the
triggers to be fully transparent to users, and we want to limit the possibility
that users provide a link that overrides enrollment decisions.

### Explicit enrollment

The primary use case of explicit enrollment is for requests that are http
cached, particularly autocomplete. Cached content must represent the test
bucket as part of the URL to ensure each bucket gets different responses.

Explicit enrollment of requests is performed by appending `cirrusUserTesting=<trigger>`
as a query string parameter to the URL. Ensuring test triggers are provided to
all appropriate API calls is not implemented within CirrusSearch. Appropriate
frontend code, such as searchSatisfaction.js from the WikimediaEvents
extension, must tie into UI elements they want to track and ensure those
elements include the trigger in all API search requests.

WARNING: While explicit enrollment does work on `index.php` requests, it should
not be used as a part of normal operations. It is intended only for verifying a
configuration prior to activating, and debugging issues. To that end explicit
enrollment also accepts any validly constructed trigger, it is not limited to
the existing enrollment decision.

### Sampling

For simplicity reasons sampling of auto enrollment is not supported. All
`index.php` requests will have one bucket's configuration applied when enabled.
Tracking in the frontend can still decide to sample the sessions that it will
report tracking information about. This simplicity is desirable because search
sessions can start in the frontend (on-wiki) or the backend (off-wiki). Wikis
with appropriate caches in front of MediaWiki will often have autocomplete
sessions start before a single request has made it to the backend, making it
preferable to sample in the front. If the scope of a test requires a more
limited deployment a potential workaround would be to configure multiple no-op
buckets.

## Scenarios

The following scenarios, and probably a few unlisted ones, were considered
in the design.

### Session starting on-wiki with autocomplete

- User is already on-site and has javascript loaded.
- At WMF wikis this is by far the most common search session entry point
- Upon entering the text box an API request to `cirrus-query-dump` is made
  to find out what trigger to use for this user.
- When the autocomplete sends its own API requests attach `cirrusUserTesting=<trigger>`
  as a query string parameter. If not yet available allow request without
  trigger.
- Similarly with emitted events, if we don't yet have the trigger, fire the
  event anyways with a placeholder for the trigger.
- Analysis will need to backfill test/bucket from later in session to the
  first few events.

### Session starting by following a link to blank Special:Search

- Expect that Special:Search is never cachable, true as of May 2021.
- Instrumentation must store the `wgCirrusSearchUserTesting` trigger from
  on-page js config (`mw.config.get`) somewhere that will persist between page
  loads.

### Session starting by following a link to Special:Search with a query

- Often either generated by a browser search bar or a link in a talk page.
- Expect that Special:Search is never cachable, true as of May 2021.
- Expect that the URL must not contain a `cirrusUserTesting` flag. MediaWiki
  will never generate a Special:Search URL containing this, instrumentation
  must take care to only append to API calls and never `index.php`.
- Instrumentation must store the `wgCirrusSearchUserTesting` trigger from
  on-page js config (`mw.config.get`) somewhere that will persist between page
  loads to use in future API requests.

### Session starting at Special:Search with a 'go'

- Will still trigger automatic enrollment.
- The only output is a redirect, there is no opportunity to share information
  with frontend. We cannot attach extra query parameters, like the trigger, to
  arbitrary page redirects as that would explode cache size.
  - Could reuse wprov which is ignored, for caching purposes, by the edge
    caches. Wouldn't even be out of line.
- Unlikely that js tracking code would have any way to know it got to the page
  via 'go' without having started a session in autocomplete.
