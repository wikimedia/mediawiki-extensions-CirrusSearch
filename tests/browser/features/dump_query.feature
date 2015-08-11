@clean @dump_quer @phantomjs
Feature: Can dump the query syntax
  @expect_failure
  Scenario: Can dump the query syntax
    Given I am at a random page
    When I search for main page
    And I request a dump of the query
    Then the page text contains query
      And the page text contains stats
      And the page text contains full_text search for 'main page'
      And the page text contains "path":
