@clean @phantomjs @prefix_filter
Feature: Searches with a prefix filter
  Background:
    Given I am at a random page

  Scenario: You can add the prefix to the url
    When I am at the search results page with the search prefix and the prefix prefix
    Then Prefix Test is the first search result
      But Foo Prefix Test is not in the search results

