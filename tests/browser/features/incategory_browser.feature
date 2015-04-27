@clean @filters @incategory @phantomjs
Feature: Searches with the incategory filter
  Background:
    Given I am at a random page

  Scenario: incategory: only includes pages with the category
    When I search for incategory:weaponry
    Then Catapult is in the search results
      And Amazing Catapult is in the search results
      But Two Words is not in the search results
      And there is no link to create a new page from the search result

  Scenario: incategory: can be combined with other text
    When I search for incategory:weaponry amazing
    Then Amazing Catapult is the first search result
      And there is no link to create a new page from the search result

  Scenario: -incategory: excludes pages with the category
    When I search for -incategory:weaponry incategory:twowords
    Then Two Words is the first search result
      And there is no link to create a new page from the search result

  Scenario: incategory: can handle a space after the :
    When I search for incategory: weaponry
    Then Catapult is in the search results
      And Amazing Catapult is in the search results
      But Two Words is not in the search results
      And there is no link to create a new page from the search result
