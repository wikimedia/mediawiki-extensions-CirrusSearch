@clean @phantomjs @more_like_this
Feature: More like an article
  Background:
    Given I am at a random page

  Scenario: Searching for morelike:<page that doesn't exist> returns no results
    When I search for morelike:IDontExist
    Then there are no search results

  Scenario: Searching for morelike:<page> returns pages that are "like" that page
    When I search for morelike:More Like Me 1
    Then More Like Me is in the first search result
    But More Like Me 1 is not in the search results

  Scenario: Searching for morelike:<page>|<page>|<page> returns pages that are "like" all those pages
    When I search for morelike:More Like Me 1|More Like Me Set 2 Page 1|More Like Me Set 3 Page 1
    Then More Like Me is part of a search result
    And More Like Me Set 2 is part of a search result
    And More Like Me Set 3 is part of a search result
    But More Like Me 1 is not in the search results
		And More Like Me Set 2 Page 1 is not in the search results
		And More Like Me Set 3 Page 1 is not in the search results
