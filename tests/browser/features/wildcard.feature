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

  Scenario Outline: Wildcards match plain matches
    When I search for pi<wildcard>les
    Then Two Words is the first search result
  Examples:
    | wildcard |
    | *        |
    | ?k       |
    | c?       |

  Scenario Outline: Wildcards don't match stemmed matches
    When I search for pi<wildcard>kle
    Then there are no search results
  Examples:
    | wildcard |
    | *        |
    | ?k       |
