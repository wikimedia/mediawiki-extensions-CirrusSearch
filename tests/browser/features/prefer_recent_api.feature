@clean @api @prefer_recent
Feature: Searches with prefer-recent
  @expect_failure
  Scenario Outline: Recently updated articles are prefered if prefer-recent: is specified
    When I api search for PreferRecent First OR Second OR Third
    Then PreferRecent Second Second is the first api search result
    When I api search for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Third is the first api search result
  Examples:
    |   options   |
    | 1,.001      |
    | 1,0.001     |
    | 1,.0001     |
    | .99,.0001   |
    | .99,.001    |

  @expect_failure
  Scenario Outline: You can specify prefer-recent: in such a way that being super recent isn't enough
    When I api search for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Second Second is the first api search result
  Examples:
    |  options  |
    |           |
    | 1         |
    | 1,1       |
    | 1,.1      |
    | .4,.0001  |
