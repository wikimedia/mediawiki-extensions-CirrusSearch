Goals
-----

* Runs `composer test` checks
* Runs phpunit tests
* Can set breakpoints in test cases
* Can set breakpoints in maintenance scripts
* Can set breakpoints in web requests

Pre-Requisites
--------------

* docker and docker-compose
* phpstorm 2022.1 (maybe others)


Install MediaWiki + CirrusSearch
--------------------------------

```sh
git clone https://gitlab.wikimedia.org/mhurd/mediawiki-cirrus-docker
```

Adjust `docker-compose.override.yml` to use the cirrus-elasticsearch image:
```yaml
  image: docker-registry.wikimedia.org/dev/cirrus-elasticsearch:6.8.23-s0
```

Run the environment build:
```sh
make freshinstallwithcirrussearch
```

Configure CirrusSearch
----------------------

The `mediawiki-cirrus-docker` environment has created a mediawiki directory,
and within it a `LocalSettings.php` file. The defaults are not compatible with
the integration test suite, to fix remove the configuration of `$wgCirrusSearchServers`
and instead use:
```php
$wgCirrusSearchClusters = [
  'default' => [ 'elasticsearch' ]
];
```

Run tests
---------

At this point mediawiki should be installed and available at http://localhost:8080

By default the composer requirements for CirrusSearch are not installed, install them now:
```sh
docker-compose exec mediawiki composer -d extensions/CirrusSearch install
```

After which we should be able to run the `composer test` checks:
```sh
docker-compose exec mediawiki composer -d extensions/CirrusSearch test
```


PHPUnit tests can also be run. Note that some of these will fail due to expecting certain things
to be configured, as long as they mostly work we are ok for the moment. Will get back to this
in a moment.
```sh
docker exec -it mediawiki_mediawiki_1 /usr/bin/php tests/phpunit/phpunit.php --filter CirrusSearch
```


Setup XDebug
------------

Using xdebug while running the test suite should "just work", getting it working
with interactive requests requires some additional xdebug config. Due to

In `mediawiki/.env` replace the following env var. Note that by design `start_with_request=trigger`
doesn't work and this will always attempt to connect to the debugger. See https://bugs.xdebug.org/2070
for details. When not debugging set `mode=develop` to avoid the connections. Also not that
`discover_client_host=1` only works for debugging web requests. If you need to debug a maintenance script
it will need to be replaced with `client_host=<ip>` with `ip` routing from the container to your
development host.

```sh
XDEBUG_CONFIG='mode=debug start_with_request=trigger discover_client_host=1 client_port=9000 idekey=PHPSTORM'
```

Setup PHPStorm (2022.1)
-----------------------
1. Select `Open File or Project`, point at the mediawiki directory created earlier
    1. Select `Trust Project` if prompted
2. Open `File > Settings`
    1. navigate to the top-level `PHP` settings
        1. Click `...` to the right of the `CLI Interpreter` dropdown
        2. Click `+` in top left, select 'From Docker, Vagrant, VM, WSL, ...`
        3. Select `Docker Compose` from radio buttons
        4. Select the `New` button next to the `Server:` selection.
        5. In the dialog accept the defaults by pressing 'OK' (on my ubuntu it defaulted to unix socket at /var/run/docker.sock)
        7. Under `Service` select `mediawiki`
        8. Under `Environment Variables` input `XDEBUG_CONFIG=''`
        9. Click OK, should bring us back to `CLI Interpreters` modal, php version should now be populated with explicit version info.
        10. Under `Lifecycle` section select `Connect to existing container` [1]
        11. Select OK, finishing out the `CLI Interpreters` modal.
    2. Expand the `PHP` section of settings sidebar and select `PHP > Test Frameworks`
        1. Click the `-` to remove the pre-existing `Local` configuration, if present.
        2. Add a new configuration by clicking the `+` and selecting `PHPUnit by Remote Interpreter`.
        3. When prompted to choose an interpreter select the `mediawiki` one we created a moment ago
        4. Finishing the dialog PHPStorm should now have detected the installed PHPUnit version. If not verify the mediawiki
           container has been restarted since the last update to .env or hover the error message for a more verbose error.
    3. Select OK, finishing out all modals and coming back to the standard development environment
3. Open `Run > Web Server Debug Validation`
    1. In `Url to validation script` input `http://127.0.0.1:8080/w`
    2. Click `Validate`. This should give a bunch of green check marks if everything works. If failing double check
       XDEBUG_CONFIG has `mode=debug` and not `mode=develop` and restart the mediawiki docker container.

Running PHPUnit from PHPStorm
-----------------------------
* Open a test file, for example `WeightedTagsHooksTest.php` from CirrusSearch.
* Select `Run > Run` from toolbar. From the provided configurations choose 'WeightedTagsHooksTest (PHPUnit)`
* The test should run and say `OK (8 tests, 20 assertions)`
* Scroll down to an individual test. Clicking the blank space between the line number and the text input should toggle a red circle (a breakpoint).
* Next to each test function there should be a play button (triangle). Click the button for the relevant test and select 'Debug 'testConfigureWeighted...`. The test should run and stop at the line we put the breakpoint on, it will let you step through execution from here.

Debugging Web Requests from PHPStorm
------------------------------------
1. Ensure `mode=debug` is set in `XDEBUG_CONFIG` of the `mediawiki/.env` file and the mediawiki container has been restarted since it was set.
2. Select `Run > Start Listening for PHP Debug Connections`
3. Visit `http://localhost:8080` in your web browser.
4. On first connection phpstorm should open a modal, `Incoming Connection from Xdebug`
    1. When prompted to select the appropriate file select the top level `mediawiki/index.php` file
5. Because we've set no breakpoints you should now get a notification `Debug session was finished without being paused`
6. Open up a CirrusSearch\CirrusSearch and set a breakpoint inside the constructor
7. type a letter into the autocomplete, phpunit should now stop inside the constructor and let
   you look around.

Notes
-----

[1] debugging tests will work, possibly better, with lifecycle set to the
 default (docker run). The benefit of using docker exec is consistency of
 verifying debugging works the same between various ways to start debugging.
