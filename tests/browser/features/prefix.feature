Feature: Searches with a prefix filter
  Background:
    Given I am at a random page

  @prefix_filter
  Scenario: You can add the prefix to the url
    When I am at the search results page with the search prefix and the prefix prefix
    Then Prefix Test is the first search result
    But Foo Prefix Test is not in the search results

  @prefix_filter
  Scenario: The prefix: filter filters results to those with titles prefixed by value
    When I search for prefix prefix:prefix
    Then Prefix Test is the first search result
    But Foo Prefix Test is not in the search results

  @prefix_filter
  Scenario: The prefix: filter interprets spaces literally
    When I search for prefix prefix:prefix tes
    Then Prefix Test is the first search result

  @prefix_filter
  Scenario: It is ok to start the query with the prefix filter
    When I search for prefix:prefix tes
    Then Prefix Test is the first search result

  @prefix_filter
  Scenario: It is ok to specify an empty prefix filter
    When I search for prefix test prefix:
    Then Prefix Test is the first search result

  @prefix_filter
  Scenario: The prefix: filter can be used to apply a namespace and a title prefix
    When I search for prefix:talk:prefix tes
    Then Talk:Prefix Test is the first search result
    But Prefix Test is not in the search results

  @prefix_filter
  Scenario: The prefix: filter can be used to apply a namespace without a title prefix
    When I search for prefix test prefix:talk:
    Then Talk:Prefix Test is the first search result
    But Prefix Test is not in the search results

  @prefix_filter
  Scenario: The prefix: filter can be used to filter to subpages
    When I search for prefix test aaaa prefix:Prefix Test/
    Then Prefix Test/AAAA is the first search result
    But Prefix Test AAAA is not in the search results

  @prefix_filter
  Scenario: The prefix: filter can be used to filter to subpages starting with some title
    When I search for prefix test aaaa prefix:Prefix Test/aa
    Then Prefix Test/AAAA is the first search result
    But Prefix Test AAAA is not in the search results
