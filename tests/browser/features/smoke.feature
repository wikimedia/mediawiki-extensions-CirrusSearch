#
# This file is subject to the license terms in the COPYING file found in the
# CirrusSearch top-level directory and at
# https://git.wikimedia.org/blob/mediawiki%2Fextensions%2FCirrusSearch/HEAD/COPYING. No part of
# CirrusSearch, including this file, may be copied, modified, propagated, or
# distributed except according to the terms contained in the COPYING file.
#
# Copyright 2012-2014 by the Mediawiki developers. See the CREDITS file in the
# CirrusSearch top-level directory and at
# https://git.wikimedia.org/blob/mediawiki%2Fextensions%2FCirrusSearch/HEAD/CREDITS
#
@clean @en.wikipedia.beta.wmflabs.org @test2.wikipedia.org
Feature: Smoke test

  Scenario: Search suggestions
    Given I am at a random page
    When I search for: main
    Then a list of suggested pages should appear
      And Main Page should be the first result

  Scenario: Fill in search term and click search
    Given I am at a random page
    When I search for: ma
      And I click the search button
    Then I should land on Search Results page

  Scenario: Search with accent yields result page with accent
    Given I am at a random page
    When I search for África
    Then the page I arrive on has title África
