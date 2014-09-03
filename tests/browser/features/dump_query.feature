@clean @phantomjs @dump_query
Feature: Can dump the query syntax
  Background:
    Given I am at a random page

  Scenario: Can dump the query syntax
    When I search for main page
    And I request a dump of the query
    Then the page text contains query
    And the page text contains stats
    And the page text contains full_text search for 'main page'
    And the page text contains "path":
