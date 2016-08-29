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
@api @suggest
Feature: Suggestion API test

  Scenario: Search suggestions
    When I ask suggestion API for main
     Then the API should produce list containing Main Page

  Scenario: Created pages suggestions
    When I ask suggestion API for x-m
      Then the API should produce list containing X-Men

  Scenario: Nothing to suggest
    When I ask suggestion API for jabberwocky
      Then the API should produce empty list

  Scenario: Ordering
    When I ask suggestion API for x-m
      Then the API should produce list starting with X-Men

  Scenario: Fuzzy
    When I ask suggestion API for xmen
      Then the API should produce list starting with X-Men

  Scenario Outline: Search redirects shows the best redirect
    When I ask suggestion API for <term>
      Then the API should produce list containing <suggested>
  Examples:
    |   term      |    suggested      |
    | eise        | Eisenhardt, Max   |
    | max         | Max Eisenhardt    |
    | magnetu     | Magneto           |

  Scenario Outline: Search prefers exact match over fuzzy match and ascii folded
    When I ask suggestion API for <term>
      Then the API should produce list starting with <suggested>
  Examples:
    |   term      |    suggested      |
    | max         | Max Eisenhardt    |
    | mai         | Main Page         |
    | eis         | Eisenhardt, Max   |
    | ele         | Elektra           |
    | éle         | Électricité       |

  Scenario Outline: Search prefers exact db match over partial prefix match
    When I ask suggestion API at most 2 items for <term>
      Then the API should produce list starting with <first>
      And the API should produce list containing <other>
  Examples:
    |   term      |   first  | other  |
    | Ic          |  Iceman  |  Ice   |
    | Ice         |   Ice    | Iceman |

  Scenario: Ordering & limit
    When I ask suggestion API at most 1 item for x-m
      Then the API should produce list starting with X-Men
      And the API should produce list of length 1

  Scenario Outline: Search fallback to prefix search if namespace is provided
    When I ask suggestion API for <term>
      Then the API should produce list starting with <suggested>
  Examples:
    |   term      |    suggested        |
    | Special:    | Special:ActiveUsers |
    | Special:Act | Special:ActiveUsers |

  Scenario Outline: Search prefers main namespace over crossns redirects
    When I ask suggestion API for <term>
      Then the API should produce list starting with <suggested>
  Examples:
    |   term      |    suggested      |
    | V           | Venom             |
    | V:          | V:N               |
    | Z           | Zam Wilson        |
    | Z:          | Z:Navigation      |

  Scenario: Default sort can be used as search input
    When I ask suggestion API for Wilson
      Then the API should produce list starting with Sam Wilson
