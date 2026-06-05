# Integration tests

The `*.feature` files in `features/` are Cucumber/[WebdriverIO] scenarios that
exercise CirrusSearch end-to-end against a live, multi-wiki MediaWiki +
OpenSearch environment.

They are driven by a separate tool, the **cirrus-integration-test-runner**,
which builds the environment with [mwcli]'s docker dev environment (`mwdd`) and
runs the suite inside it. The same tool powers *Cindy-the-browser-test-bot*,
which auto-votes on CirrusSearch patches in Gerrit.

[WebdriverIO]: https://webdriver.io/
[mwcli]: https://www.mediawiki.org/wiki/Cli

## Setup

Clone the runner and follow its README for the one-time prerequisites (the
`mw` binary, docker, etc.):

    git clone git@gitlab.wikimedia.org:repos/search-platform/cirrus-integration-test-runner.git
    cd cirrus-integration-test-runner

The runner's `README.md` is the source of truth for environment details (image
versions, the list of wikis it creates, and the wmcloud deployment used by the
CI bot). Everything below assumes commands are run from the runner checkout.

Build the environment. This destroys any running environment and recreates it,
installing MediaWiki + the required extensions, OpenSearch, and the
`cirrustestwiki`, `commonswiki`, `ruwiki`, and `wikidatawiki` wikis:

    ./create-env.sh

`create-env.sh` retains the MediaWiki source, wiki configuration, and vendor
directories between runs, so re-running it is the standard way to reset to a
known-good state after a test corrupts the data.

> **Tip:** the runner expects the environment on port 8080. If something else
> already held 8080 when the environment was created, fix it with
> `mw docker env set PORT 8080` and re-run `./create-env.sh`.

## Run the tests

Run the whole suite:

    ./run-integration.sh

Run a single feature with the `CIRRUS_FEATURES` environment variable:

    CIRRUS_FEATURES=full_text_browser.feature ./run-integration.sh

It also accepts globs — for example, just the API-transport scenarios:

    CIRRUS_FEATURES='*_api.feature' ./run-integration.sh

Feature files are suffixed by transport: `_api.feature` (HTTP API) or
`_browser.feature` (driven through Chromium via WebdriverIO). Name new files
accordingly.

## What lives where

This repo owns the tests and their wiring; the runner owns the environment.

* `features/*.feature` — the Cucumber scenarios, plus step definitions and
  support code under `features/step_definitions/` and `features/support/`.
* `articles/` — wiki content imported as test fixtures.
* `config/wdio.conf.js` — base WebdriverIO config. `config/wdio.conf.mwdd.js`
  layers on the mwdd-specific bits (Chromium paths, JUnit output, and the
  per-wiki `*.mediawiki.local.wmftest.net:8080` URLs) and is the config the
  runner selects via `WEBDRIVER_IO_CONFIG_FILE`.
* `config/cirrus-integration-test-runner.json` — knobs the runner reads out of
  *this* repo so a CirrusSearch patch can change them. Currently it pins the
  `fresh` browser-test image (the Node image the suite executes in).

## Running from a CirrusSearch checkout

For day-to-day work it is convenient to symlink the runner's scripts into the
CirrusSearch checkout root so they can be invoked alongside the source under
test:

    ln -s /path/to/cirrus-integration-test-runner/create-env.sh
    ln -s /path/to/cirrus-integration-test-runner/run-integration.sh
    ln -s /path/to/cirrus-integration-test-runner/run-unit-tests.sh
