@clean @phantomjs @setup_main @removed_text
Feature: Removed text
  Background:
    Given I am at a random page

  Scenario: Searching fox text that is inside <video> and <audio> tags doesn't find it
    When I search for "JavaScript disabled"
    Then there are no search results

  Scenario: Searching fox text that is inside autocollapse tags doesn't find it
    When I search for in autocollapse
    Then there are no search results
