@clean @api @suggestions
Feature: Did you mean
  Scenario: Uncommon phrases spelled correctly don't get suggestions even if one of the words is very uncommon
    When I api search for nobel prize
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
