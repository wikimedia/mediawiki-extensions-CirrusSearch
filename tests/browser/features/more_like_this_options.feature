@clean @phantomjs @setup_main
Feature: More like this queries with custom settings
  Background:
      Given I am at a random page

  @setup_namespaces
  Scenario: Searching for morelike:<page> with the title field and filtering with the word length
    When I set More Like This Options to title field, word length to 3 and I search for morelike:More Like Me 1
    Then More Like Me 2 is in the search results
      And More Like Me 3 is in the search results
      And More Like Me 4 is in the search results
      And More Like Me 5 is in the search results
      And More Like Me Set 3 Page 3 is in the search results
      But More Like Me 1 is not in the search results
      And ChangeMe is not in the search results

  Scenario: Searching for morelike:<page> with the title field and filtering with the percent terms to match
    When I set More Like This Options to title field, percent terms to match to 70% and I search for morelike:More Like Me 1
    Then More Like Me 2 is in the search results
      And More Like Me 3 is in the search results
      And More Like Me 4 is in the search results
      And More Like Me 5 is in the search results
      And More Like Me Set 3 Page 3 is in the search results
      But More Like Me 1 is not in the search results
      And ChangeMe is not in the search results

  Scenario: Searching for morelike:<page> with the title field and bad settings give no results
    When I set More Like This Options to bad settings and I search for morelike:More Like Me 1
    Then there are no search results

  Scenario: Searching for morelike:<page> with the title field and settings with poor precision
    When I set More Like This Options to title field, word length to 2 and I search for morelike:More Like Me 1
    Then ChangeMe is in the search results

  Scenario: Searching for morelike:<page> with the all field works even if cirrusMtlUseFields is set to yes
    When I set More Like This Options to all field, word length to 4 and I search for morelike:More Like Me 1
    Then More Like Me 2 is in the search results
      And More Like Me 3 is in the search results
      And More Like Me 4 is in the search results
      And More Like Me 5 is in the search results
      But ChangeMe is not in the search results
