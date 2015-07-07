@clean @api @prefer_recent
Feature: Searches with prefer-recent
  Scenario Outline: Recently updated articles are prefered if prefer-recent: is specified
    Given I api search for PreferRecent First OR Second OR Third
      And PreferRecent Second Second is the first api search result
    When I api search with now set to 1970-01-01T01:00:00Z for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Third is the first api search result
  Examples:
    |   options   |
    | 1,.001      |
    | 1,0.001     |
    | 1,.005      |
    | .9,.01      |

  Scenario Outline: You can specify prefer-recent: in such a way that being super recent isn't enough
    Given I api search for PreferRecent First OR Second OR Third
      And PreferRecent Second Second is the first api search result
    When I api search with now set to 1970-01-01T01:00:00Z for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Second Second is the first api search result
  Examples:
    |  options  |
    |           |
    | 1         |
    | 1,1       |
    | 1,.1      |
    | .4,.0001  |
