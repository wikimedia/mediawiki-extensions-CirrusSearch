@clean @phantomjs
Feature: Searches with combined filters
  Background:
    Given I am at a random page

  @filters
  Scenario Outline: Filters can be combined
    When I search for <term>
    Then <first_result> is the first search result
    And there is no link to create a new page from the search result
  Examples:
    |                  term                   | first_result |
    | incategory:twowords intitle:catapult    | none         |
    | incategory:twowords intitle:"Two Words" | Two Words    |
    | incategory:alpha incategory:beta        | AlphaBeta    |
