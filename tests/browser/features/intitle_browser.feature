@clean @filters @intitle @phantomjs
Feature: Searches with the intitle filter
  Background:
    Given I am at a random page

  Scenario: intitle: only includes pages with the title
    When I search for intitle:catapult
    Then Catapult is in the search results
      And Amazing Catapult is in the search results
      But Two Words is not in the search results
      And there is no link to create a new page from the search result

  Scenario: -intitle: excludes pages with part of the title
    When I search for -intitle:amazing intitle:catapult
    Then Catapult is the first search result
      And Amazing Catapult is not in the search results
      And there is no link to create a new page from the search result

  Scenario: -intitle: doesn't highlight excluded title
    When I search for -intitle:catapult two words
    Then Two Words is the first search result
      And ffnonesenseword catapult pickles anotherword is the highlighted text of the first search result

  Scenario: intitle: will accept a space after its :
    When I search for intitle: catapult
    Then Catapult is in the search results
      And Amazing Catapult is in the search results
      But Two Words is not in the search results
      And there is no link to create a new page from the search result
