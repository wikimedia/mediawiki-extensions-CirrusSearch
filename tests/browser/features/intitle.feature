@clean @phantomjs @filters
Feature: Searches with the intitle filter
  Background:
    Given I am at a random page

  Scenario: intitle: only includes pages with the title
    When I search for intitle:catapult
    Then Catapult is in the search results
    And Amazing Catapult is in the search results
    But Two Words is not in the search results
    And there is no link to create a new page from the search result

  Scenario: intitle: can be combined with other text
    When I search for intitle:catapult amazing
    Then Amazing Catapult is the first search result
    And Two Words is not in the search results

  Scenario: -intitle: excludes pages with part of the title
    When I search for -intitle:amazing intitle:catapult
    Then Catapult is the first search result
    And Amazing Catapult is not in the search results
    And there is no link to create a new page from the search result

  Scenario: -intitle: doesn't highlight excluded title
    When I search for -intitle:catapult two words
    Then Two Words is the first search result
    And ffnonesenseword catapult pickles anotherword is the highlighted text of the first search result

  @wildcards
  Scenario: intitle: can take a wildcard
    When I search for intitle:catapul*
    Then Catapult is the first search result

  @wildcards @setup_main
  Scenario: intitle: can take a wildcard and combine it with a regular wildcard
    When I search for intitle:catapul* amaz*
    Then Amazing Catapult is the first search result

  Scenario: intitle: will accept a space after its :
    When I search for intitle: catapult
    Then Catapult is in the search results
    And Amazing Catapult is in the search results
    But Two Words is not in the search results
    And there is no link to create a new page from the search result

  Scenario: intitle: will accept a space after its : with quoted titles
    When I search for intitle: "amazing catapult"
    Then Amazing Catapult is the first search result
    And Two Words is not in the search results
