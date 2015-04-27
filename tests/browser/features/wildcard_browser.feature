@clean @phantomjs @wildcard
Feature: Searches that contain wildcard matches
  Background:
    Given I am at a random page

  Scenario Outline: Searching with a single wildcard finds expected results
    When I search for catapu<wildcard>
    Then Catapult is the first search result
      And there is no link to create a new page from the search result
  Examples:
    | wildcard |
    | *        |
    | ?t       |
    | l?       |
