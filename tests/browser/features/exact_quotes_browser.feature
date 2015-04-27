@clean @exact_quotes @phantomjs
Feature: Searches that contain quotes
  Background:
    Given I am at a random page

  @setup_main
  Scenario: Searching for a word in quotes disbles stemming (can't find plural with singular)
    When I search for "pickle"
    Then there are no search results
      And there is no link to create a new page from the search result
