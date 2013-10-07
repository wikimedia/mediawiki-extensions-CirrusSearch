Feature: Prefix filter on url
  @prefix_filter
  Scenario: You can add the prefix to the url
    When I am at the search results page with the search prefix and the prefix prefix
    Then Prefix Test is the first search result
    But Foo Prefix Test is not in the search results
