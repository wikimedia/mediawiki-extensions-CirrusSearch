@clean
Feature: Searches with prefer-recent
  Background:
    Given I am at a random page

  @prefer_recent
  Scenario Outline: Recently updated articles are prefered if prefer-recent: is specified
    When I search for PreferRecent First OR Second OR Third
    Then PreferRecent Second Second is the first search result
    When I search for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Third is the first search result
    And there is no link to create a new page from the search result
  Examples:
    |   options   |
    | 1,.001      |
    | 1,0.001     |
    | 1,.0001     |
    | .99,.0001   |
    | .99,.001    |
    | .8,.0001    |

  @prefer_recent
  Scenario Outline: You can specify prefer-recent: in such a way that being super recent isn't enough
    When I search for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Second Second is the first search result
    And there is no link to create a new page from the search result
  Examples:
    |  options  |
    |           |
    | 1         |
    | 1,1       |
    | 1,.1      |
    | .4,.0001  |
