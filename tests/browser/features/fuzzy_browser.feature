@clean @phantomjs @setup_main
Feature: Searches that contain fuzzy matches
  Background:
    Given I am at a random page

  Scenario: Searching for <text>~ activates fuzzy search
    When I search for ffnonesensewor~
    Then Two Words is the first search result
      And there is no link to create a new page from the search result

  Scenario Outline: Searching for <text>~<number between 0 and 1> activates fuzzy search
    When I search for ffnonesensewor~<number>
    Then Two Words is the first search result
      And there is no link to create a new page from the search result
  Examples:
    | number |
    | .8     |
    | 0.8    |
    | 1      |
