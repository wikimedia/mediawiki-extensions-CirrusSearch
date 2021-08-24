# Selenium tests

For more information see https://www.mediawiki.org/wiki/Selenium

Tests here are running daily in selenium-daily-beta-CirrusSearch Jenkins job. For documentation see https://www.mediawiki.org/wiki/Selenium/How-to/Run_tests_using_selenium-daily_Jenkins_job

## Setup

    export MW_SERVER=https://en.wikipedia.beta.wmflabs.org

## Run all specs

In one terminal window or tab start Chromedriver:

    chromedriver --url-base=wd/hub --port=4444

In another terminal tab or window:

    npm run @selenium-test

## Run specific tests

Filter by file name:

    npm run @selenium-test -- --spec tests/selenium/specs/[FILE-NAME]

Filter by file name and test name:

    npm run @selenium-test -- --spec tests/selenium/specs/[FILE-NAME] --mochaOpts.grep [TEST-NAME]