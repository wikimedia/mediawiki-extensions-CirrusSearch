@bad_syntax @clean @api
Feature: Searches with syntax errors
  Scenario Outline: Searching with a / doesn't cause a degraded search result
    When I api search for main <term>
    Then Main Page is the first api search result
  Examples:
    |      term      |
    | intitle:/page  |
    | Main/Page      |
