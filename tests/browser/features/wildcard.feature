Feature: Searches that contain wildcard matches
  Background:
    Given I am at a random page

  @wildcards @setup_main
  Scenario: searching with a single wildcard finds expected results
    When I search for catapul*
    Then Catapult is the first search result

  @wildcards @setup_main
  Scenario: wildcards match plain matches
    When I search for pi*les
    Then Two Words is the first search result

  @wildcards @setup_main
  Scenario: wildcards don't match stemmed matches
    When I search for pi*le
    Then there are no search results
