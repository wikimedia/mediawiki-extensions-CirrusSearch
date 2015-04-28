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

