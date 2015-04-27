@clean @phantomjs @suggestions
Feature: Did you mean
  Background:
    Given I am at a random page

  Scenario: Common phrases spelled incorrectly get suggestions
    When I search for popular cultur
    Then popular *culture* is suggested

  Scenario: No suggestions on pages that are not the first
    When I search for popular cultur
    And I jump to offset 20 then
    Then there is no suggestion

  Scenario: Uncommon phrases spelled incorrectly get suggestions even if they contain words that are spelled correctly on their own
    When I search for noble prize
    Then *nobel* prize is suggested

  Scenario: Suggestions can come from redirect titles when redirects are included in search
    When I search for Rrr Worrd
    Then rrr *word* is suggested

  Scenario Outline: Special search syntax is preserved in suggestions (though sometimes moved around)
    When I search for <term>
    Then <suggested> is suggested
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
