@clean @api @suggestions
Feature: Did you mean
  Scenario: Uncommon phrases spelled correctly don't get suggestions even if one of the words is very uncommon
    When I api search for nobel prize
    Then there is no api suggestion

  Scenario: No suggestions on pages that are not the first
    When I api search with offset 20 for popular cultur
    Then there is no api suggestion

  @stemming
  Scenario: Suggestions do not show up when a full title matches but with stemming
    When I api search for stemmingsingleword
    Then there is no api suggestion

  @stemming
  Scenario: Suggestions do not show up when a full multi word title matches but with stemming
    When I api search for stemming multiword
    Then there is no api suggestion

  @stemming
  Scenario: Suggestions do not show up when a full multi word title matches but with apostrophe normalization
    When I api search for stemming possessive's
    Then there is no api suggestion

  Scenario: Suggestions don't come from redirect titles when it matches an actual title
    When I api search for Noble Gasses
    Then there is no api suggestion

  Scenario: Common phrases spelled incorrectly get suggestions
    When I api search for popular cultur
    Then popular *culture* is suggested by api

  Scenario: Uncommon phrases spelled incorrectly get suggestions even if they contain words that are spelled correctly on their own
    When I api search for noble prize
    Then *nobel* prize is suggested by api

  Scenario: Suggestions can come from redirect titles when redirects are included in search
    When I api search for Rrr Worrd
    Then rrr *word* is suggested by api

  Scenario Outline: Special search syntax is preserved in suggestions (though sometimes moved around)
    When I api search for <term>
    Then <suggested> is suggested by api
  Examples:
    |                    term                   |                  suggested                  |
    | prefer-recent:noble prize                 | prefer-recent:*nobel* prize                 |
    | Template:nobel piep                       | Template:*noble pipe*                       |
    | prefer-recent:noble prize                 | prefer-recent:*nobel* prize                 |
    | incategory:prize noble prize              | incategory:prize *nobel* prize              |
    | noble incategory:prize prize              | incategory:prize *nobel* prize              |
    | hastemplate:prize noble prize             | hastemplate:prize *nobel* prize             |
    | -hastemplate:prize noble prize            | -hastemplate:prize *nobel* prize            |
    | boost-templates:"prize\|150%" noble prize | boost-templates:"prize\|150%" *nobel* prize |
    | noble prize prefix:n                      | *nobel* prize prefix:n                      |

